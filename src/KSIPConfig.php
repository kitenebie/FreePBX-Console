<?php

namespace KsipTelnet;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class KSIPConfig extends Command
{
    protected $signature   = 'make:ksip-config';
    protected $description = 'Generate migration, model, controller and routes for per-user softphone settings config';

    public function handle()
    {
        $this->generateMigration();
        $this->generateModel();
        $this->generateController();
        $this->appendRoutes();

        $this->info('KSIP softphone config scaffold generated successfully.');
        $this->info('Run: php artisan migrate');
    }

    protected function generateMigration()
    {
        $timestamp = date('Y_m_d_His');
        $path      = $this->laravel->databasePath("migrations/{$timestamp}_create_softphone_configs_table.php");
        File::put($path, $this->migrationStub());
        $this->info("Created: database/migrations/{$timestamp}_create_softphone_configs_table.php");
    }

    protected function generateModel()
    {
        $dir  = $this->laravel->make('path') . '/Models';
        $path = $dir . '/SoftphoneConfig.php';

        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (File::exists($path)) {
            $this->warn('SoftphoneConfig model already exists, skipping.');
            return;
        }

        File::put($path, $this->modelStub());
        $this->info('Created: app/Models/SoftphoneConfig.php');
    }

    protected function generateController()
    {
        $dir  = $this->laravel->make('path') . '/Http/Controllers/Api';
        $path = $dir . '/SoftphoneConfigController.php';

        if (!File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (File::exists($path)) {
            $this->warn('SoftphoneConfigController already exists, skipping.');
            return;
        }

        File::put($path, $this->controllerStub());
        $this->info('Created: app/Http/Controllers/Api/SoftphoneConfigController.php');
    }

    protected function appendRoutes()
    {
        // Try api.php first, fall back to web.php
        $apiPath = $this->laravel->basePath('routes/api.php');
        $webPath = $this->laravel->basePath('routes/web.php');
        $target  = File::exists($apiPath) ? $apiPath : $webPath;

        $content = File::get($target);
        if (str_contains($content, 'SoftphoneConfigController')) {
            $this->warn('Softphone config routes already exist, skipping.');
            return;
        }

        File::append($target, $this->routesStub());
        $this->info('Appended routes to ' . basename($target));
    }

    // -------------------------------------------------------------------------
    // Stubs
    // -------------------------------------------------------------------------

    protected function migrationStub(): string
    {
        return <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('softphone_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // SIP Connection
            $table->string('server')->nullable();
            $table->enum('ws_protocol', ['ws', 'wss'])->default('ws');
            $table->string('ws_port')->default('8088');
            $table->string('extension')->nullable();
            $table->string('password')->nullable();
            $table->string('display_name')->nullable();

            // Codecs
            $table->json('audio_codecs')->nullable(); // e.g. ["PCMU","PCMA"]
            $table->json('video_codecs')->nullable(); // e.g. ["VP8","H264"]

            // UI toggles
            $table->boolean('enabled_bubble')->default(true);
            $table->boolean('show_dialer')->default(true);
            $table->boolean('show_setting')->default(true);
            $table->boolean('show_opacity')->default(true);
            $table->boolean('answer_with_video_call')->default(false);
            $table->boolean('show_incoming_call_video_btn')->default(true);
            $table->boolean('show_incoming_call_audio')->default(true);
            $table->boolean('fullscreen')->default(false);

            // Recording
            $table->boolean('auto_record')->default(false);
            $table->string('recording_dir')->default('video/recordings/Ksip');
            $table->string('upload_api_url')->nullable();

            // Position
            $table->integer('position_top')->nullable();
            $table->integer('position_bottom')->nullable();
            $table->integer('position_left')->nullable();
            $table->integer('position_right')->nullable();

            $table->timestamps();

            $table->unique('user_id'); // one config per user
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('softphone_configs');
    }
};
PHP;
    }

    protected function modelStub(): string
    {
        return <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SoftphoneConfig extends Model
{
    protected $fillable = [
        'user_id',
        'server',
        'ws_protocol',
        'ws_port',
        'extension',
        'password',
        'display_name',
        'audio_codecs',
        'video_codecs',
        'enabled_bubble',
        'show_dialer',
        'show_setting',
        'show_opacity',
        'answer_with_video_call',
        'show_incoming_call_video_btn',
        'show_incoming_call_audio',
        'fullscreen',
        'auto_record',
        'recording_dir',
        'upload_api_url',
        'position_top',
        'position_bottom',
        'position_left',
        'position_right',
    ];

    protected $casts = [
        'audio_codecs'                 => 'array',
        'video_codecs'                 => 'array',
        'enabled_bubble'               => 'boolean',
        'show_dialer'                  => 'boolean',
        'show_setting'                 => 'boolean',
        'show_opacity'                 => 'boolean',
        'answer_with_video_call'       => 'boolean',
        'show_incoming_call_video_btn' => 'boolean',
        'show_incoming_call_audio'     => 'boolean',
        'fullscreen'                   => 'boolean',
        'auto_record'                  => 'boolean',
        'position_top'                 => 'integer',
        'position_bottom'              => 'integer',
        'position_left'                => 'integer',
        'position_right'               => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
PHP;
    }

    protected function controllerStub(): string
    {
        return <<<'PHP'
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SoftphoneConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SoftphoneConfigController extends Controller
{
    /**
     * GET /api/softphone-config
     * Returns the authenticated user's config, or null if not set.
     */
    public function show(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['data' => null], 200);
        }

        $config = SoftphoneConfig::where('user_id', $request->user()->id)->first();

        if (!$config) {
            return response()->json(['data' => null], 200);
        }

        return response()->json(['data' => $config], 200);
    }

    /**
     * POST /api/softphone-config
     * Creates or updates the authenticated user's config (upsert).
     */
    public function save(Request $request)
    {
        if (!Auth::check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $validated = $request->validate([
            'server'                        => 'nullable|string|max:255',
            'ws_protocol'                   => 'nullable|in:ws,wss',
            'ws_port'                       => 'nullable|string|max:10',
            'extension'                     => 'nullable|string|max:50',
            'password'                      => 'nullable|string|max:255',
            'display_name'                  => 'nullable|string|max:255',
            'audio_codecs'                  => 'nullable|array',
            'audio_codecs.*'                => 'string',
            'video_codecs'                  => 'nullable|array',
            'video_codecs.*'                => 'string',
            'enabled_bubble'                => 'nullable|boolean',
            'show_dialer'                   => 'nullable|boolean',
            'show_setting'                  => 'nullable|boolean',
            'show_opacity'                  => 'nullable|boolean',
            'answer_with_video_call'        => 'nullable|boolean',
            'show_incoming_call_video_btn'  => 'nullable|boolean',
            'show_incoming_call_audio'      => 'nullable|boolean',
            'fullscreen'                    => 'nullable|boolean',
            'auto_record'                   => 'nullable|boolean',
            'recording_dir'                 => 'nullable|string|max:255',
            'upload_api_url'                => 'nullable|string|max:255',
            'position_top'                  => 'nullable|integer',
            'position_bottom'               => 'nullable|integer',
            'position_left'                 => 'nullable|integer',
            'position_right'                => 'nullable|integer',
        ]);

        $config = SoftphoneConfig::updateOrCreate(
            ['user_id' => $request->user()->id],
            $validated
        );

        return response()->json(['data' => $config], 200);
    }
}
PHP;
    }

    protected function routesStub(): string
    {
        return <<<'PHP'


// KSIP Softphone Config Routes
use App\Http\Controllers\Api\SoftphoneConfigController;

Route::middleware(['auth:sanctum,web'])->group(function () {
    Route::get('/softphone-config',  [SoftphoneConfigController::class, 'show']);
    Route::post('/softphone-config', [SoftphoneConfigController::class, 'save']);
});
PHP;
    }
}
