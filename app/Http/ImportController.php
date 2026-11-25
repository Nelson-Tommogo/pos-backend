<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class ImportController
{
    public const COLUMNS = [
        'products' => ['name', 'sku', 'barcode', 'category', 'variant', 'price_minor', 'cost_minor'],
        'customers' => ['name', 'email', 'phone', 'address', 'credit_limit_minor'],
        'suppliers' => ['name', 'email', 'phone', 'address'],
        'users' => ['name', 'email', 'password', 'role', 'branch_id'],
    ];

    public function template(Workspace $workspace, string $resource)
    {
        $this->authorize($workspace, $resource);

        return response(implode(',', self::COLUMNS[$resource])."\n", 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$resource.'.csv"',
        ]);
    }

    public function import(Request $request, Workspace $workspace, DirectoryController $directory, AdministrationController $administration, string $resource)
    {
        $this->authorize($workspace, $resource);
        $request->validate(['file' => 'required|file|max:5120|extensions:csv,xlsx']);
        $file = $request->file('file');
        $rows = strtolower($file->getClientOriginalExtension()) === 'csv'
            ? $this->csv($file->getRealPath())
            : $this->xlsx($file->getRealPath());
        $headers = array_map(fn ($value) => strtolower(trim($value)), array_shift($rows) ?? []);
        if ($headers !== []) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }
        if ($rows === [] || $headers === [] || count(array_unique($headers)) !== count($headers)
            || array_diff($headers, self::COLUMNS[$resource])) {
            $this->invalid('Use the CSV template headers and include at least one record.');
        }

        $ids = DB::transaction(function () use ($workspace, $directory, $administration, $resource, $rows, $headers): array {
            DB::table('shops')->where('id', $workspace->shop)->lockForUpdate()->first();
            $ids = [];
            foreach ($rows as $index => $row) {
                try {
                    if (count($row) > count($headers)) {
                        $this->invalid('The row has extra columns.');
                    }
                    $data = array_combine($headers, array_pad($row, count($headers), null));
                    $data = array_map(fn ($value) => $value === null || $value === '' ? null : $value, $data);
                    if ($resource === 'users') {
                        $ids[] = $administration->saveUser($workspace, $data)->id;

                        continue;
                    }
                    if ($resource === 'products') {
                        $data = $directory->productData($workspace, $data);
                    } else {
                        if ($resource === 'customers' && ($data['credit_limit_minor'] ?? null) === null) {
                            unset($data['credit_limit_minor']);
                        }
                        $data = validator($data, $directory->rules($workspace, $resource, null))->validate();
                    }
                    $ids[] = DB::table($resource)->insertGetId($data + ['shop_id' => $workspace->shop]);
                } catch (ValidationException $exception) {
                    $errors = [];
                    foreach ($exception->errors() as $field => $messages) {
                        $errors['rows.'.($index + 2).'.'.$field] = $messages;
                    }
                    throw ValidationException::withMessages($errors);
                }
            }

            return $ids;
        });
        $request->attributes->set('audit_details', ['record_ids' => $ids]);

        return response()->json(['imported' => count($rows)], 201);
    }

    private function csv(string $path): array
    {
        $file = fopen($path, 'rb');
        $rows = [];
        try {
            while (($row = fgetcsv($file, escape: '')) !== false) {
                $this->append($rows, $row);
            }
        } finally {
            fclose($file);
        }

        return $rows;
    }

    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->invalid('The workbook could not be opened.');
        }
        try {
            $workbook = $this->xml($zip, 'xl/workbook.xml');
            $sheet = $workbook->sheets->sheet[0] ?? null;
            $id = (string) ($sheet?->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
            $relations = $this->xml($zip, 'xl/_rels/workbook.xml.rels');
            $target = null;
            foreach ($relations->Relationship as $relation) {
                if ((string) $relation['Id'] === $id && str_ends_with((string) $relation['Type'], '/worksheet')) {
                    $target = (string) $relation['Target'];
                    break;
                }
            }
            $target = str_starts_with($target ?? '', '/') ? ltrim($target, '/') : 'xl/'.$target;
            if (! preg_match('~^xl/worksheets/[a-zA-Z0-9_.-]+\.xml$~D', $target)) {
                $this->invalid('The first worksheet could not be located.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml($zip, 'xl/sharedStrings.xml')->si as $value) {
                    $strings[] = $this->cellText($value);
                }
            }
            $rows = [];
            foreach ($this->xml($zip, $target)->sheetData->row as $line) {
                $row = [];
                foreach ($line->c as $cell) {
                    if (! preg_match('/^([A-G])[0-9]+$/D', (string) $cell['r'], $match)) {
                        if (isset($cell->v) || isset($cell->is) || isset($cell->f)) {
                            $this->invalid('The worksheet has extra columns.');
                        }
                        continue;
                    }
                    if (isset($cell->f)) {
                        $this->invalid('Replace formulas with values before importing.');
                    }
                    $column = ord($match[1]) - ord('A');
                    $row[$column] = match ((string) $cell['t']) {
                        's' => $strings[(int) $cell->v] ?? null,
                        'inlineStr' => $this->cellText($cell->is),
                        default => isset($cell->v) ? (string) $cell->v : null,
                    };
                }
                if ($row !== []) {
                    $row += array_fill(0, max(array_keys($row)) + 1, null);
                    ksort($row);
                    $this->append($rows, array_values($row));
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function xml(ZipArchive $zip, string $name): SimpleXMLElement
    {
        $entry = $zip->statName($name);
        if ($entry === false || $entry['size'] > 5 * 1024 * 1024) {
            $this->invalid('A required workbook entry is missing or too large.');
        }
        $text = $zip->getFromName($name, 5 * 1024 * 1024);
        if ($text === false || str_contains($text, "\0") || stripos($text, '<!DOCTYPE') !== false
            || ($xml = @simplexml_load_string($text, options: LIBXML_NONET)) === false) {
            $this->invalid('The workbook XML is invalid.');
        }

        return $xml;
    }

    private function cellText(SimpleXMLElement $element): string
    {
        if (isset($element->t)) {
            return (string) $element->t;
        }

        $text = '';
        foreach ($element->r as $run) {
            $text .= (string) $run->t;
        }

        return $text;
    }

    private function append(array &$rows, array $row): void
    {
        if (count($row) > 7) {
            $this->invalid('The file has too many columns.');
        }
        foreach ($row as $value) {
            if ($value !== null && (strlen($value) > 1024 || str_contains($value, "\0") || ! mb_check_encoding($value, 'UTF-8'))) {
                $this->invalid('Use UTF-8 text with at most 1024 bytes per field.');
            }
        }
        if (array_filter($row, fn ($value) => $value !== null && trim($value) !== '') === []) {
            return;
        }
        if (count($rows) >= 501) {
            $this->invalid('A file can contain at most 500 records.');
        }
        while ($row !== [] && trim((string) end($row)) === '') {
            array_pop($row);
        }
        $rows[] = $row;
    }

    private function authorize(Workspace $workspace, string $resource): void
    {
        $workspace->authorize(in_array($resource, ['users', 'customers'], true) ? ['administrator'] : Workspace::STOCK);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
