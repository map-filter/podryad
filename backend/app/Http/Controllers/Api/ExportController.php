<?php

namespace App\Http\Controllers\Api;

use App\Exports\RegistryXlsx;
use App\Http\Controllers\Controller;
use App\Imports\WorkbookImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExportController extends Controller
{
    use Concerns;

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    public function registry(Request $request): BinaryFileResponse
    {
        $path = (new RegistryXlsx($this->org($request)))->build();

        return response()->download($path, 'Реестр_сроков_'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => self::XLSX])->deleteFileAfterSend();
    }

    public function template(Request $request): BinaryFileResponse
    {
        $path = WorkbookImport::template($this->org($request));

        return response()->download($path, 'Шаблон_импорта_работников.xlsx', ['Content-Type' => self::XLSX])->deleteFileAfterSend();
    }

    public function import(Request $request): JsonResponse
    {
        $this->requireEdit($request);
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $file = $request->file('file');
        if (strtolower($file->getClientOriginalExtension()) !== 'xlsx') {
            throw ValidationException::withMessages(['file' => 'Нужен файл Excel в формате .xlsx']);
        }
        $dry = $request->boolean('dry_run', true);
        try {
            $result = (new WorkbookImport($this->org($request)))->run($file->getRealPath(), $dry);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return response()->json($result);
    }
}
