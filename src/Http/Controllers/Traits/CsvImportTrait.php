<?php

namespace VelaBuild\Core\Http\Controllers\Traits;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use SpreadsheetReader;

/**
 * CSV import for a controller's own records.
 *
 * The controller says what it imports and who may: $csvImportModel (a model
 * under VelaBuild\Core\Models) and $csvImportGate (the permission needed).
 * The model used to come from the request, with no permission checked at
 * all, so any account that could sign in could write rows straight into
 * vela_users, vela_roles or vela_configs.
 */
trait CsvImportTrait
{
    /**
     * The model this controller imports into, once the user may do so.
     */
    protected function csvImportModelName(): string
    {
        $model = property_exists($this, 'csvImportModel') ? $this->csvImportModel : null;
        $gate  = property_exists($this, 'csvImportGate') ? $this->csvImportGate : null;

        abort_unless($model && $gate && Gate::allows($gate), 403, '403 Forbidden');

        return $model;
    }

    public function processCsvImport(Request $request)
    {
        $modelName = $this->csvImportModelName();

        try {
            // Only a file parseCsvImport stored, never a path of the caller's choosing.
            $filename = basename((string) $request->input('filename', ''));
            $path     = Storage::disk('local')->path('csv_import/' . $filename);

            abort_unless($filename !== '' && is_file($path), 404);

            $hasHeader = $request->input('hasHeader', false);

            $model = "VelaBuild\\Core\\Models\\" . $modelName;

            // insert() skips mass-assignment protection, so the columns are
            // limited to what the model would accept from a form anyway.
            $fillable = (new $model())->getFillable();
            // fields[column index] = column name.
            $fields = array_filter((array) $request->input('fields', []));
            $fields = array_flip(array_intersect($fields, $fillable));

            $reader = new SpreadsheetReader($path);
            $insert = [];

            foreach ($reader as $key => $row) {
                if ($hasHeader && $key == 0) {
                    continue;
                }

                $tmp = [];
                foreach ($fields as $header => $k) {
                    if (isset($row[$k])) {
                        $tmp[$header] = $row[$k];
                    }
                }

                if (count($tmp) > 0) {
                    $insert[] = $tmp;
                }
            }

            $for_insert = array_chunk($insert, 100);

            foreach ($for_insert as $insert_item) {
                $model::insert($insert_item);
            }

            $rows  = count($insert);
            $table = Str::plural($modelName);

            File::delete($path);

            session()->flash('message', trans('vela::global.app_imported_rows_to_table', ['rows' => $rows, 'table' => $table]));

            // Back to the list the import started from, and never off this site.
            $to = (string) $request->input('redirect', '');
            if (! str_starts_with($to, url('/') . '/') && $to !== url('/')) {
                $to = url()->previous();
            }

            return redirect($to);
        } catch (\Exception $ex) {
            throw $ex;
        }
    }

    public function parseCsvImport(Request $request)
    {
        $modelName = $this->csvImportModelName();

        $file = $request->file('csv_file');
        $request->validate([
            'csv_file' => 'mimes:csv,txt',
        ]);

        $path      = $file->path();
        $hasHeader = $request->input('header', false) ? true : false;

        $reader  = new SpreadsheetReader($path);
        $headers = $reader->current();
        $lines   = [];

        $i = 0;
        while ($reader->next() !== false && $i < 5) {
            $lines[] = $reader->current();
            $i++;
        }

        $filename = Str::random(10) . '.csv';
        $file->storeAs('csv_import', $filename, 'local');

        $fullModelName = "VelaBuild\\Core\\Models\\" . $modelName;

        $model     = new $fullModelName();
        $fillables = $model->getFillable();

        $redirect = url()->previous();

        // The import posts back to this controller's own process route.
        $routeName = Str::replaceLast('parseCsvImport', 'processCsvImport', (string) $request->route()?->getName());

        return view('vela::csvImport.parseInput', compact('headers', 'filename', 'fillables', 'hasHeader', 'modelName', 'lines', 'redirect', 'routeName'));
    }
}
