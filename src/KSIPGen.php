<?php

namespace KsipTelnet;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KSIPGen extends Command
{
    protected $signature   = 'make:ksipgen';
    protected $description = 'Generate CallRecordingController, migration, and API routes for KSIP call recordings';

    public function handle()
    {
        $this->generateController();
        $this->generateMigration();
        $this->appendRoutes();

        $this->info('KSIP call recording scaffold generated successfully.');
    }

    protected function generateController()
    {
        $dir  = $this->laravel->make('path') . '/Http/Controllers/Api';
        $path = $dir . '/CallRecordingController.php';

        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (File::exists($path)) {
            $this->warn('CallRecordingController already exists, skipping.');
            return;
        }

        File::put($path, $this->controllerStub());
        $this->info('Created: app/Http/Controllers/Api/CallRecordingController.php');
    }

    protected function generateMigration()
    {
        $timestamp = date('Y_m_d_His');
        $filename  = $this->laravel->databasePath("migrations/{$timestamp}_create_call_recordings_table.php");

        File::put($filename, $this->migrationStub());
        $this->info("Created: database/migrations/{$timestamp}_create_call_recordings_table.php");
    }

    protected function appendRoutes()
    {
        $path   = $this->laravel->basePath('routes/api.php');
        $routes = $this->routesStub();

        if (strpos(File::get($path), 'CallRecordingController') !== false) {
            $this->warn('Routes already exist in api.php, skipping.');
            return;
        }

        File::append($path, "\n" . $routes);
        $this->info('Appended routes to routes/api.php');
    }

    protected function controllerStub(): string
    {
        return <<<'PHP'
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallRecording;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CallRecordingController extends Controller
{
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recording' => 'required|file|mimes:webm,mp4,avi|max:102400', // 100MB max
            'date' => 'required|date',
            'timestamp' => 'required|string',
            'extension' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $file = $request->file('recording');
            $date = $request->input('date');
            $timestamp = $request->input('timestamp');
            $extension = $request->input('extension', 'unknown');

            // Check if recording for this date already exists
            $existingRecording = CallRecording::where('recording_date', $date)
                ->where('extension', $extension)
                ->first();

            if ($existingRecording) {
                return response()->json([
                    'success' => true,
                    'message' => 'Recording already exists for this date',
                    'data' => $existingRecording
                ], 200);
            }

            // Create directory structure: recordings/{year}/{month}/{extension}/
            $year = date('Y', strtotime($date));
            $month = date('m', strtotime($date));
            $directory = "recordings/{$year}/{$month}/{$extension}";

            // Store file in public storage
            $filename = $timestamp . '.' . $file->getClientOriginalExtension();
            $filePath = $file->storeAs($directory, $filename, 'public');

            // Save to database
            $recording = CallRecording::create([
                'extension' => $extension,
                'filename' => $filename,
                'file_path' => $filePath,
                'recording_date' => $date,
                'timestamp' => $timestamp,
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
                'uploaded_successfully' => true,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Recording uploaded successfully',
                'data' => $recording
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request)
    {
        $query = CallRecording::query();

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('recording_date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('recording_date', '<=', $request->end_date);
        }

        // Filter by extension
        if ($request->has('extension')) {
            $query->where('extension', $request->extension);
        }

        $recordings = $query->orderBy('recording_date', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $recordings
        ]);
    }

    public function show($id)
    {
        $recording = CallRecording::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $recording
        ]);
    }

    public function download($id)
    {
        $recording = CallRecording::findOrFail($id);

        if (!Storage::disk('public')->exists($recording->file_path)) {
            return response()->json([
                'success' => false,
                'message' => 'File not found'
            ], 404);
        }

        return Storage::disk('public')->download($recording->file_path, $recording->filename);
    }

    public function delete($id)
    {
        $recording = CallRecording::findOrFail($id);

        // Delete file from storage
        if (Storage::disk('public')->exists($recording->file_path)) {
            Storage::disk('public')->delete($recording->file_path);
        }

        // Delete database record
        $recording->delete();

        return response()->json([
            'success' => true,
            'message' => 'Recording deleted successfully'
        ]);
    }
}
PHP;
    }

    protected function migrationStub(): string
    {
        return <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallRecording extends Model
{
    protected $fillable = [
        'extension',
        'filename',
        'file_path',
        'recording_date',
        'timestamp',
        'file_size',
        'mime_type',
        'uploaded_successfully',
    ];

    protected $casts = [
        'recording_date' => 'date',
        'uploaded_successfully' => 'boolean',
    ];
}
PHP;
    }

    protected function routesStub(): string
    {
        return <<<'PHP'
<?php

use App\Http\Controllers\Api\CallRecordingController;
use Illuminate\Support\Facades\Route;

Route::prefix('recordings')->group(function () {
    Route::post('/upload', [CallRecordingController::class, 'upload']);
    Route::get('/', [CallRecordingController::class, 'index']);
    Route::get('/{id}', [CallRecordingController::class, 'show']);
    Route::get('/{id}/download', [CallRecordingController::class, 'download']);
    Route::delete('/{id}', [CallRecordingController::class, 'delete']);
});
PHP;
    }
}
