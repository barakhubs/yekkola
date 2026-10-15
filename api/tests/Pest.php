<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Integrations\Sms\SmsSender;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
| Feature tests boot the app and run against the Postgres test database (yekkola_test).
| Unit tests are plain PHP — no framework, no database.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

require_once __DIR__.'/Helpers/authoring.php';

/*
| Sign-in helpers (PRD-01). The log SMS driver records messages, so tests read codes from it.
*/

/** Headers that make a request look like it comes from the web app (Sanctum stateful domain). */
const WEB_APP = ['Referer' => 'http://localhost:3000/', 'Origin' => 'http://localhost:3000'];

/** Headers the mobile app sends (no bot check until app attestation lands). */
const MOBILE_APP = ['X-Device-Id' => 'install-android-0001'];

function lastSmsCode(string $e164): string
{
    /** @var App\Integrations\Sms\LogSmsSender $sms */
    $sms = app(SmsSender::class);
    $messages = array_values(array_filter($sms->sent(), fn (array $m) => $m['to'] === $e164));

    expect($messages)->not->toBeEmpty("No SMS sent to {$e164}");
    preg_match('/\b(\d{6})\b/', end($messages)['message'], $match);

    return $match[1];
}

/**
 * @return array<string, string>
 */
function mobileDevice(string $installId = 'install-android-0001', string $platform = 'android'): array
{
    return ['install_id' => $installId, 'platform' => $platform, 'name' => 'Tecno Spark', 'app_version' => '1.0.0'];
}

/**
 * Full mobile sign-in; returns the verify response.
 *
 * @param  array<string, mixed>  $extra
 */
function signInMobile(string $phone = '+243812345678', string $installId = 'install-android-0001', array $extra = []): TestResponse
{
    test()->seed(RolesAndPermissionsSeeder::class);
    test()->postJson('/api/v1/auth/otp/request', ['phone' => $phone], ['X-Device-Id' => $installId])->assertAccepted();

    return test()->postJson('/api/v1/auth/otp/verify', [
        'phone' => $phone,
        'code' => lastSmsCode($phone),
        'device' => mobileDevice($installId),
        ...$extra,
    ]);
}

/** Full web sign-in; returns the verify response (session cookie set). */
function signInWeb(string $phone = '+243812345678'): TestResponse
{
    test()->seed(RolesAndPermissionsSeeder::class);
    test()->postJson('/api/v1/auth/otp/request', ['phone' => $phone, 'bot_token' => 'ok'], WEB_APP)->assertAccepted();

    return test()->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => lastSmsCode($phone)], WEB_APP);
}

/** Bearer header for a token. */
function bearer(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/** Forget cached guards so the next request authenticates from scratch (like a new HTTP request would). */
function freshAuth(): void
{
    app('auth')->forgetGuards();
}

function userByPhone(string $e164): User
{
    return User::query()->where('phone_e164', $e164)->sole();
}

/*
| Professor & staff helpers (PRD-02, PRD-09).
*/

/**
 * A signed-in staff member (Sanctum::actingAs) with a role and optional extra permissions.
 *
 * @param  list<App\Domain\Identity\Enums\Permission>  $extra
 */
function actingAsStaff(App\Domain\Identity\Enums\Role $role = App\Domain\Identity\Enums\Role::Moderator, array $extra = []): User
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create();
    $user->assignRole($role->value);
    foreach ($extra as $permission) {
        $user->givePermissionTo($permission->value);
    }
    Laravel\Sanctum\Sanctum::actingAs($user);

    return $user;
}

/** An approved professor who accepted the current agreement, signed in via Sanctum::actingAs. */
function actingAsProfessor(string $name = 'Marie Kabila'): App\Domain\Professors\Models\ProfessorProfile
{
    test()->seed(RolesAndPermissionsSeeder::class);
    $user = User::factory()->create(['name' => $name]);
    $user->assignRole(App\Domain\Identity\Enums\Role::Student->value, App\Domain\Identity\Enums\Role::Professor->value);
    App\Domain\Professors\Models\TermsAcceptance::record($user->id, App\Domain\Professors\Enums\LegalDocument::ProfessorAgreement, null);
    $profile = App\Domain\Professors\Models\ProfessorProfile::query()->create([
        'user_id' => $user->id,
        'slug' => app(App\Domain\Professors\Actions\ProfessorSlugs::class)->unique($name),
        'display_name' => $name,
    ]);
    Laravel\Sanctum\Sanctum::actingAs($user);

    return $profile;
}

/** A small valid PDF upload (real PDF magic bytes, so MIME sniffing accepts it). */
function fakePdf(string $name = 'diplome.pdf'): Illuminate\Http\UploadedFile
{
    return Illuminate\Http\UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
}
