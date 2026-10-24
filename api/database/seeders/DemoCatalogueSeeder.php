<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Authoring\Actions\CourseReview;
use App\Domain\Authoring\Actions\CreateCourse;
use App\Domain\Authoring\Actions\EditDraft;
use App\Domain\Authoring\Actions\SetLessonContent;
use App\Domain\Authoring\Actions\SyncMediaAsset;
use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\GrantEnrollment;
use App\Domain\Learning\Actions\RefreshRatings;
use App\Domain\Learning\Enums\EnrollmentSource;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Learning\Models\Review;
use App\Domain\Platform\Models\Province;
use App\Domain\Professors\Actions\ProfessorSlugs;
use App\Domain\Professors\Enums\KycStatus;
use App\Domain\Professors\Enums\LegalDocument;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Professors\Models\TermsAcceptance;
use App\Integrations\Video\FakeVideoProvider;
use App\Integrations\Video\VideoEventType;
use App\Integrations\Video\VideoProvider;
use App\Integrations\Video\VideoWebhookEvent;
use FPDF;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo catalogue for local and staging (client demos, front-end work): professors from several provinces,
 * free and paid courses in USD and CDF, with video, audio, document and quiz lessons, students and reviews.
 * Built through the real authoring and review actions, with the fake video driver.
 *
 * Run: php artisan db:seed --class=DemoCatalogueSeeder. Idempotent: skips if the demo professors exist.
 * Demo phone numbers never receive SMS (nobody signs in as them).
 */
final class DemoCatalogueSeeder extends Seeder
{
    /** Demo accounts use this range; no OTP is ever requested for them. */
    private const PHONE_PREFIX = '+24399000';

    public function run(
        CreateCourse $create,
        EditDraft $edit,
        SetLessonContent $content,
        CourseReview $review,
        VideoProvider $videos,
        SyncMediaAsset $sync,
        GrantEnrollment $grant,
        RefreshRatings $ratings,
    ): void {
        if (! app()->environment(['local', 'staging']) || ! $videos instanceof FakeVideoProvider) {
            throw new RuntimeException('The demo catalogue is for local/staging with the fake video driver only.');
        }
        if (User::query()->where('phone_e164', self::PHONE_PREFIX.'0001')->exists()) {
            $this->command->info('Demo catalogue already seeded.');

            return;
        }

        $this->call([ProvinceSeeder::class, RolesAndPermissionsSeeder::class, CategorySeeder::class]);

        // All or nothing: a failure leaves no half-built demo behind.
        DB::transaction(fn () => $this->build($create, $edit, $content, $review, $videos, $sync, $grant, $ratings));

        $this->command->info('Demo catalogue seeded: 3 professors, 5 courses.');
    }

    private function build(
        CreateCourse $create,
        EditDraft $edit,
        SetLessonContent $content,
        CourseReview $review,
        VideoProvider $videos,
        SyncMediaAsset $sync,
        GrantEnrollment $grant,
        RefreshRatings $ratings,
    ): void {
        $staff = $this->user(9, 'Équipe Yekkola', [Role::Moderator]);

        $professors = [
            $this->professor(1, 'Marie Kabongo', 'Professeure de mathématiques, 12 ans de préparation à l’Examen d’État', 'CD-HK', verified: true),
            $this->professor(2, 'Jean-Paul Mukendi', 'Expert-comptable et formateur', 'CD-KC', verified: true),
            $this->professor(3, 'Grace Amani', 'Enseignante d’anglais et interprète', 'CD-NK', verified: false),
        ];

        /** @var list<array{0: ProfessorProfile, 1: string, 2: string, 3: string, 4: list<string>, 5: string, 6: bool, 7: int|null, 8: string|null}> $courses */
        $courses = [
            [$professors[0], 'Algèbre pour l’Examen d’État', 'Équations, inéquations et fonctions, exercice par exercice', 'exetat-sciences', ['exetat'], 'fr', true, null, null],
            [$professors[0], 'Géométrie et trigonométrie', 'Tout le programme de géométrie de la 5e et 6e', 'mathematiques', ['secondaire', 'exetat'], 'fr', false, 1_500, 'USD'],
            [$professors[1], 'Comptabilité générale : les bases', 'Journal, grand livre et bilan pour débutants', 'comptabilite-finance', ['professionnel', 'universite'], 'fr', false, 2_500_000, 'CDF'],
            [$professors[1], 'Excel pour la gestion', 'Tableaux, formules et graphiques pour le bureau', 'bureautique', ['professionnel'], 'fr', false, 1_000, 'USD'],
            [$professors[2], 'Spoken English for Beginners', 'Everyday conversations with audio lessons you can download', 'anglais', ['secondaire', 'professionnel'], 'en', true, null, null],
        ];

        $students = collect(range(10, 17))->map(fn (int $n) => $this->user($n, ['Christelle Mbuyi', 'Patrick Ilunga', 'Divine Kasongo', 'Joël Tshibangu', 'Ruth Mwamba', 'Samuel Lukusa', 'Esther Ngoy', 'David Kalala'][$n - 10], [Role::Student]));

        foreach ($courses as $i => [$professor, $title, $subtitle, $category, $audience, $language, $free, $price, $currency]) {
            $course = $create->handle($professor, ['title' => $title]);
            $course->update([
                'category_id' => Category::query()->where('slug', $category)->value('id'),
                'audience' => $audience,
                'language' => $language,
                'level' => $i % 2 === 0 ? 'beginner' : 'intermediate',
                'is_free' => $free,
                'price_minor' => $price,
                'currency' => $currency,
            ]);

            $edit->updateDetails($course, [
                'subtitle' => $subtitle,
                'description' => "{$subtitle}.\n\nChaque leçon est courte et peut être téléchargée pour être suivie sans connexion.",
                'outcomes' => ['Comprendre les notions clés', 'S’exercer avec des quiz corrigés', 'Réviser hors ligne avec les fiches'],
                'requirements' => ['Un téléphone ou un ordinateur'],
            ]);
            $edit->setCover($course, UploadedFile::fake()->image('cover.jpg', 1280, 720));

            $intro = $edit->addSection($course, $language === 'en' ? 'Getting started' : 'Introduction');
            $video = $edit->addLesson($course, ['section_id' => $intro->id, 'type' => 'video', 'title' => $language === 'en' ? 'Welcome' : 'Bienvenue', 'is_preview' => true]);
            $this->completeUpload($content, $videos, $sync, $course, $video->id);
            $sheet = $edit->addLesson($course, ['section_id' => $intro->id, 'type' => 'document', 'title' => $language === 'en' ? 'Course sheet' : 'Fiche de révision', 'is_preview' => true]);
            $content->attachDocument($course, $sheet->id, $this->pdf($title));

            $practice = $edit->addLesson($course, ['section_id' => $intro->id, 'type' => 'audio', 'title' => $language === 'en' ? 'Listen and repeat' : 'Leçon audio']);
            $this->completeUpload($content, $videos, $sync, $course, $practice->id);

            $quizSection = $edit->addSection($course, $language === 'en' ? 'Practice' : 'Exercices');
            $quiz = $edit->addLesson($course, ['section_id' => $quizSection->id, 'type' => 'quiz', 'title' => $language === 'en' ? 'Quick quiz' : 'Quiz de révision']);
            $content->saveQuiz($course, $quiz->id, ['pass_mark_pct' => 60, 'questions' => [[
                'type' => 'single', 'prompt' => '2 + 3 = ?', 'explanation' => $language === 'en' ? 'Addition.' : 'Une addition.',
                'options' => [['text' => '5', 'is_correct' => true], ['text' => '6', 'is_correct' => false]],
            ]]]);

            $review->submit($course->refresh());
            $review->approve($course->refresh()->draftVersion, $staff, null);
            $course->refresh();

            $this->enrollAndReview($course, $students->slice(0, 3 + $i)->values()->all(), $grant, $ratings);
        }

        Course::query()->whereIn('id', Course::query()->listed()->orderBy('created_at')->limit(3)->pluck('id'))
            ->get()->each(fn (Course $c, int $position) => $c->forceFill(['featured_position' => $position + 1])->save());
    }

    /**
     * @param  list<Role>  $roles
     */
    private function user(int $n, string $name, array $roles): User
    {
        $user = User::query()->create([
            'phone_e164' => self::PHONE_PREFIX.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'name' => $name,
            'phone_verified_at' => now(),
        ]);
        $user->assignRole(array_map(fn (Role $r) => $r->value, $roles));

        return $user;
    }

    private function professor(int $n, string $name, string $headline, string $province, bool $verified): ProfessorProfile
    {
        $user = $this->user($n, $name, [Role::Student, Role::Professor]);
        TermsAcceptance::record($user->id, LegalDocument::ProfessorAgreement, null);

        return ProfessorProfile::query()->create([
            'user_id' => $user->id,
            'slug' => app(ProfessorSlugs::class)->unique($name),
            'display_name' => $name,
            'headline' => $headline,
            'bio' => "{$headline}. J’enseigne avec des exemples concrets et des exercices corrigés.",
            'province_id' => Province::query()->where('code', $province)->value('id'),
            'kyc_status' => $verified ? KycStatus::Verified : KycStatus::None,
            'verified_at' => $verified ? now() : null,
        ]);
    }

    private function completeUpload(SetLessonContent $content, VideoProvider $videos, SyncMediaAsset $sync, Course $course, string $lessonId): void
    {
        /** @var FakeVideoProvider $videos */
        $upload = $content->createMediaUpload($course, $lessonId, (string) config('app.url'));
        $assetId = (string) $videos->completeUpload((string) $upload['asset']->provider_upload_id);
        $asset = $videos->getAsset($assetId);
        $sync->fromEvent(new VideoWebhookEvent('demo-'.$assetId, VideoEventType::AssetReady, $assetId, $upload['asset']->provider_upload_id, $asset->passthrough, []));
    }

    private function pdf(string $title): UploadedFile
    {
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->Cell(0, 12, (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $title), 0, 1);
        $pdf->SetFont('Helvetica', '', 12);
        $pdf->MultiCell(0, 7, 'Fiche de revision : notions essentielles, exemples et exercices.');

        return UploadedFile::fake()->createWithContent('fiche.pdf', $pdf->Output('S'));
    }

    /**
     * @param  list<User>  $students
     */
    private function enrollAndReview(Course $course, array $students, GrantEnrollment $grant, RefreshRatings $ratings): void
    {
        $bodies = ['Très clair, merci professeur !', 'Les quiz m’ont beaucoup aidé.', 'Bon cours, j’aurais aimé plus d’exercices.', 'Excellent pour réviser hors ligne.'];

        foreach ($students as $index => $student) {
            $grant->handle($student->id, $course->id, EnrollmentSource::Admin);
            Enrollment::query()->where('user_id', $student->id)->where('course_id', $course->id)->update(['progress_pct' => 50]);
            if ($index < 4) {
                Review::query()->create([
                    'user_id' => $student->id,
                    'course_id' => $course->id,
                    'rating' => [5, 4, 5, 3][$index],
                    'body' => $bodies[$index],
                ]);
            }
        }
        $course->forceFill(['enrollment_count' => count($students)])->saveQuietly();
        $ratings->lock($course->id);
        $ratings->handle($course->id);
    }
}
