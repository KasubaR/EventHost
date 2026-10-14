<?php

namespace Tests\Feature;

use App\Http\Requests\StoreGuestImportRequest;
use App\Imports\GuestImportFile;
use App\Jobs\ImportEventGuests;
use App\Models\Event;
use App\Models\Guest;
use App\Models\GuestImport;
use App\Models\User;
use App\Services\GuestImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;
use ZipArchive;

/**
 * The guest CSV/Excel import checklist: empty and malformed files, missing columns, duplicates, encodings, quoting,
 * formulas, hostile files, interrupted uploads, and big files imported in the background. Rules the add-guest form
 * shares (email and phone format, name length) are covered in GuestAddEdgeCasesTest.
 */
class GuestImportEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(GuestImport::DISK);
    }

    private function import(Event $event, string $contents, string $filename = 'guests.csv'): TestResponse
    {
        return $this->actingAs($event->user)->post(route('events.guests.import.store', $event), [
            'file' => UploadedFile::fake()->createWithContent($filename, $contents),
        ]);
    }

    private function upload(Event $event, UploadedFile $file): TestResponse
    {
        return $this->actingAs($event->user)->post(route('events.guests.import.store', $event), ['file' => $file]);
    }

    private function event(?User $owner = null): Event
    {
        return Event::factory()->for($owner ?? User::factory()->create())->create();
    }

    private function xlsx(array $rows, ?array $secondSheet = null): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        if ($secondSheet !== null) {
            $spreadsheet->createSheet()->fromArray($secondSheet);
        }

        $path = tempnam(sys_get_temp_dir(), 'guest_xlsx').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function csvRows(int $count, string $prefix = 'Guest'): string
    {
        $csv = "name,email\n";
        for ($i = 1; $i <= $count; $i++) {
            $csv .= "{$prefix} {$i},".strtolower($prefix)."{$i}@example.test\n";
        }

        return $csv;
    }

    // ── Empty files and headings ─────────────────────────────────────────────

    public function test_an_empty_file_is_refused(): void
    {
        $event = $this->event();

        $this->import($event, '')->assertSessionHasErrors(['file' => GuestImportFile::EMPTY_MESSAGE]);
        $this->assertSame(0, $event->guests()->count());
    }

    public function test_a_heading_row_with_nobody_under_it_is_refused(): void
    {
        $event = $this->event();

        $this->import($event, "name,email,phone\n")->assertSessionHasErrors(['file' => GuestImportFile::EMPTY_MESSAGE]);
        $this->import($event, "name,email,phone\n,,\n\n")->assertSessionHasErrors(['file' => GuestImportFile::EMPTY_MESSAGE]);
    }

    public function test_a_file_without_a_name_column_is_refused_and_names_the_columns_it_has(): void
    {
        $event = $this->event();

        $this->import($event, "email,phone\na@example.test,0971234567\n")
            ->assertSessionHasErrors(['file' => 'The first row needs a column called "name" for each guest\'s name. This file\'s first row has "email", "phone". Rename the name column, or download the template.']);

        $this->import($event, "Guest Name,Email\nMwila,m@example.test\n")
            ->assertSessionHasErrors('file');
        $this->assertStringContainsString('"Guest Name"', session('errors')->first('file'));

        $this->assertSame(0, $event->guests()->count());
    }

    public function test_headings_match_whatever_their_case_and_spacing(): void
    {
        $event = $this->event();

        $this->import($event, " NAME , Email \nMwila Banda,mwila@example.test\n")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_created', 1);

        $this->assertDatabaseHas('guests', ['event_id' => $event->id, 'name' => 'Mwila Banda', 'email' => 'mwila@example.test']);
    }

    // ── Rows that used to vanish ─────────────────────────────────────────────

    public function test_a_row_with_no_name_is_reported_and_a_blank_line_is_ignored(): void
    {
        $event = $this->event();

        $this->import($event, "name,email\n,nameless@example.test\nReal Person,\n,\n")
            ->assertSessionHas('import_created', 1)
            ->assertSessionHas('import_invalid', 1)
            ->assertSessionHas('import_problems', [['row' => 2, 'message' => 'the name is empty.']]);
    }

    public function test_the_same_row_twice_is_one_guest_but_the_same_name_with_other_details_is_two(): void
    {
        $event = $this->event();

        $this->import($event, "name,email\nMwila Banda,\nmwila banda,\nChanda,c1@example.test\nChanda,c2@example.test\n")
            ->assertSessionHas('import_created', 3)
            ->assertSessionHas('import_skipped', 1)
            ->assertSessionHas('import_skipped_rows', [['row' => 3, 'message' => 'it repeats row 2.']]);

        $this->assertSame(2, $event->guests()->where('name', 'Chanda')->count());
    }

    public function test_skipped_rows_say_why(): void
    {
        $event = $this->event();
        Guest::factory()->for($event)->create(['email' => 'listed@example.test', 'phone' => '0961111111']);

        $this->import($event, "name,email,phone\n"
            ."One,one@example.test,0971234567\n"
            ."Two,ONE@example.test,\n"
            ."Three,,+260 97 123 4567\n"
            ."Four,listed@example.test,\n"
            ."Five,,0961111111\n")
            ->assertSessionHas('import_created', 1)
            ->assertSessionHas('import_skipped', 4)
            ->assertSessionHas('import_skipped_rows', [
                ['row' => 3, 'message' => 'row 2 has the same email.'],
                ['row' => 4, 'message' => 'row 2 has the same phone number.'],
                ['row' => 5, 'message' => 'a guest with this email is already on the list.'],
                ['row' => 6, 'message' => 'a guest with this phone number is already on the list.'],
            ]);
    }

    public function test_an_email_stored_in_other_casing_is_already_on_the_list(): void
    {
        $event = $this->event();
        Guest::factory()->for($event)->create(['email' => 'Mixed@Example.test']);

        $this->import($event, "name,email\nMixed,mixed@example.test\n")
            ->assertSessionHas('import_created', 0)
            ->assertSessionHas('import_skipped_rows', [
                ['row' => 2, 'message' => 'a guest with this email is already on the list.'],
            ]);
    }

    public function test_a_collision_the_checks_missed_skips_only_that_row(): void
    {
        $event = $this->event();

        // Stands in for a guest added by someone else after the batch was checked.
        DB::statement("CREATE TRIGGER guests_import_collision BEFORE INSERT ON guests WHEN NEW.email = 'taken@example.test' "
            ."BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: guests.event_id, guests.email'); END");

        $this->import($event, "name,email,group\n"
            ."Ann,ann@example.test,Family\n"
            ."Taken,taken@example.test,Solo\n"
            ."Ben,ben@example.test,Family\n")
            ->assertSessionHas('import_created', 2)
            ->assertSessionHas('import_skipped_rows', [
                ['row' => 3, 'message' => 'a guest with this email is already on the list.'],
            ]);

        $this->assertSame(['Ann', 'Ben'], $event->guests()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Family'], $event->guestGroups()->pluck('name')->all());
        $this->assertSame(2, $event->guestGroups()->first()->guests()->count());
    }

    public function test_the_guest_list_shows_the_skip_reasons(): void
    {
        $event = $this->event();

        $this->import($event, "name\nMwila\nMwila\n");

        $this->get(route('events.guests.index', $event))
            ->assertOk()
            ->assertSee('Import finished.')
            ->assertSee('Row 3: it repeats row 2.');
    }

    // ── Quoting, encodings and scripts ───────────────────────────────────────

    public function test_commas_and_quotes_inside_a_name_survive(): void
    {
        $event = $this->event();

        $this->import($event, "name,email\n\"Banda, Mwila\",m@example.test\n\"Chanda \"\"CJ\"\" Phiri\",c@example.test\nO'Brien,o@example.test\n")
            ->assertSessionHas('import_created', 3);

        $this->assertEqualsCanonicalizing(
            ['Banda, Mwila', 'Chanda "CJ" Phiri', "O'Brien"],
            $event->guests()->pluck('name')->all()
        );
    }

    public function test_utf8_with_a_byte_order_mark_and_non_latin_names(): void
    {
        $event = $this->event();

        $this->import($event, "\xEF\xBB\xBFname,email\nChisomo Bandá,a@example.test\n王小明,b@example.test\nسارة أحمد,c@example.test\nЖанна,d@example.test\n")
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_created', 4);

        $this->assertEqualsCanonicalizing(
            ['Chisomo Bandá', '王小明', 'سارة أحمد', 'Жанна'],
            $event->guests()->pluck('name')->all()
        );
    }

    public function test_a_windows_1252_file_from_older_excel_is_read_as_such(): void
    {
        $event = $this->event();
        $csv = mb_convert_encoding("name,email\nJosé Mwansa,jose@example.test\nRenée Zulu,renee@example.test\n", 'Windows-1252', 'UTF-8');

        $this->import($event, $csv)->assertSessionHas('import_created', 2);

        $this->assertEqualsCanonicalizing(['José Mwansa', 'Renée Zulu'], $event->guests()->pluck('name')->all());
    }

    public function test_a_semicolon_separated_file_is_read(): void
    {
        $event = $this->event();

        $this->import($event, "name;email\nMwila;m@example.test\nChanda;c@example.test\n")->assertSessionHas('import_created', 2);

        $this->assertDatabaseHas('guests', ['event_id' => $event->id, 'name' => 'Mwila', 'email' => 'm@example.test']);
    }

    // ── Formulas and hostile files ───────────────────────────────────────────

    public function test_a_formula_in_a_csv_is_stored_as_text_and_never_calculated(): void
    {
        $event = $this->event();

        $this->import($event, "name,email\n=1+1,a@example.test\n=SUM(A1:A9),b@example.test\n")->assertSessionHas('import_created', 2);

        $this->assertEqualsCanonicalizing(['=1+1', '=SUM(A1:A9)'], $event->guests()->pluck('name')->all());
    }

    public function test_an_excel_workbook_imports_its_first_sheet_only_without_calculating_formulas(): void
    {
        $event = $this->event();
        $path = $this->xlsx(
            [['name', 'email'], ['Mwila Banda', 'mwila@example.test'], ['=1+1', 'formula@example.test']],
            [['name', 'email'], ['Notes Sheet Person', 'notes@example.test']],
        );

        $this->upload($event, new UploadedFile($path, 'guests.xlsx', null, null, true))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('import_created', 2);

        $this->assertEqualsCanonicalizing(['Mwila Banda', '=1+1'], $event->guests()->pluck('name')->all());
    }

    public function test_a_file_that_is_not_a_spreadsheet_is_refused(): void
    {
        $event = $this->event();

        $this->upload($event, UploadedFile::fake()->image('guests.png'))
            ->assertSessionHasErrors(['file' => StoreGuestImportRequest::TYPE_MESSAGE]);

        $this->import($event, "<?php echo 'hi';\n", 'guests.csv')->assertSessionHasErrors('file');

        $this->import($event, 'not really a workbook', 'guests.xlsx')->assertSessionHasErrors('file');

        $this->assertSame(0, $event->guests()->count());
    }

    public function test_a_workbook_that_unzips_past_the_cap_is_refused_before_it_is_parsed(): void
    {
        config(['events.guest_import.max_unzipped_mb' => 1]);
        $event = $this->event();
        $path = $this->xlsx([['name'], ['Mwila']]);

        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('xl/media/padding.bin', str_repeat("\0", 2 * 1024 * 1024));
        $zip->close();

        $this->upload($event, new UploadedFile($path, 'guests.xlsx', null, null, true))
            ->assertSessionHasErrors(['file' => GuestImportFile::TOO_BIG_UNZIPPED_MESSAGE]);

        $this->assertSame(0, $event->guests()->count());
    }

    public function test_an_upload_that_did_not_finish_is_refused(): void
    {
        $event = $this->event();
        $path = tempnam(sys_get_temp_dir(), 'guest_import');
        file_put_contents($path, "name\nMwila\n");

        $this->upload($event, new UploadedFile($path, 'guests.csv', 'text/csv', UPLOAD_ERR_PARTIAL, true))
            ->assertSessionHasErrors(['file' => 'The upload did not finish. Check your connection and choose the file again.']);
    }

    public function test_a_file_over_the_row_limit_is_refused(): void
    {
        config(['events.guest_import.max_rows' => 5]);
        $event = $this->event();

        $this->import($event, $this->csvRows(6))
            ->assertSessionHasErrors(['file' => 'This file has 6 rows. Import up to 5 guests at a time: split the file and import each part.']);
    }

    // ── Big files run in the background ──────────────────────────────────────

    public function test_a_big_file_is_queued_and_the_request_adds_nobody(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 3]);
        $event = $this->event();

        $response = $this->import($event, $this->csvRows(5));

        $record = GuestImport::query()->sole();
        $response->assertRedirect(route('events.guests.import.show', ['event' => $event, 'guestImport' => $record]));

        $this->assertSame(0, $event->guests()->count());
        $this->assertSame(GuestImport::STATUS_QUEUED, $record->status);
        $this->assertSame(5, $record->total_rows);
        $this->assertSame('guests.csv', $record->original_name);
        Storage::disk(GuestImport::DISK)->assertExists($record->path);
        Queue::assertPushed(ImportEventGuests::class, fn (ImportEventGuests $job): bool => $job->guestImportId === $record->id);

        $this->get(route('events.guests.import.show', ['event' => $event, 'guestImport' => $record]))
            ->assertOk()
            ->assertSee('Waiting to start.')
            ->assertSee('data-import-progress-url', false)
            ->assertSee('Refresh');
    }

    public function test_the_job_imports_in_chunks_with_real_row_numbers_and_reports_once(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 3, 'events.guest_import.chunk_rows' => 2, 'events.guest_import.read_chunk_rows' => 3]);
        $event = $this->event();

        $this->import($event, "name,email\nA,a@example.test\nB,b@example.test\nC,c@example.test\n,nameless@example.test\nE,a@example.test\nF,f@example.test\n");

        $record = GuestImport::query()->sole();
        $path = $record->path;

        (new ImportEventGuests($record->id, $path))->handle();

        $record->refresh();
        $this->assertSame(GuestImport::STATUS_COMPLETED, $record->status);
        $this->assertSame(4, $record->created_count);
        $this->assertSame(6, $record->processed_rows);
        $this->assertSame([['row' => 5, 'message' => 'the name is empty.']], $record->problems);
        $this->assertSame([['row' => 6, 'message' => 'row 2 has the same email.']], $record->skipped_rows);
        $this->assertNull($record->path);
        Storage::disk(GuestImport::DISK)->assertMissing($path);
        $this->assertSame(4, $event->guests()->count());

        $this->getJson(route('events.guests.import.progress', ['event' => $event, 'guestImport' => $record]))
            ->assertOk()
            ->assertJson(['status' => 'completed', 'finished' => true, 'processed_rows' => 6, 'total_rows' => 6]);

        // The guest list shows the result once, then never again.
        $this->get(route('events.guests.index', $event))
            ->assertSee('Import of')
            ->assertSee('Row 5: the name is empty.');
        $this->assertNotNull($record->fresh()->seen_at);
        $this->get(route('events.guests.index', $event))->assertDontSee('Row 5: the name is empty.');

        $this->get(route('events.guests.import.show', ['event' => $event, 'guestImport' => $record]))
            ->assertOk()
            ->assertSee('Import finished.')
            ->assertDontSee('data-import-progress-url', false);
    }

    public function test_ten_thousand_guests_import_in_the_background_on_an_unlimited_plan(): void
    {
        Queue::fake();
        $event = $this->event(User::factory()->proPlus()->create());

        $this->import($event, $this->csvRows(10000))->assertSessionHasNoErrors();

        $this->assertSame(0, $event->guests()->count());
        $record = GuestImport::query()->sole();
        $this->assertSame(10000, $record->total_rows);

        (new ImportEventGuests($record->id, $record->path))->handle();

        $record->refresh();
        $this->assertSame(GuestImport::STATUS_COMPLETED, $record->status);
        $this->assertSame(10000, $record->created_count);
        $this->assertSame(10000, $event->guests()->count());
    }

    public function test_a_capped_plan_stops_at_its_guest_limit_in_the_background_too(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 100, 'events.guest_import.chunk_rows' => 50]);
        $event = $this->event();
        $limit = $event->guestCapacity();

        $this->import($event, $this->csvRows($limit + 20));
        $record = GuestImport::query()->sole();
        (new ImportEventGuests($record->id, $record->path))->handle();

        $record->refresh();
        $this->assertSame($limit, $record->created_count);
        $this->assertSame(20, $record->capped_count);
        $this->assertSame($limit, $event->guests()->count());
    }

    public function test_a_second_import_waits_for_the_running_one_but_a_dead_one_does_not_block(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 3]);
        $event = $this->event();

        $this->import($event, $this->csvRows(5));
        $this->import($event, "name\nLate Guest\n")->assertSessionHasErrors(['file' => GuestImporter::BUSY_MESSAGE]);
        $this->import($event, $this->csvRows(5, 'Other'))->assertSessionHasErrors(['file' => GuestImporter::BUSY_MESSAGE]);

        $this->get(route('events.guests.import.create', $event))->assertSee('are still being imported');

        GuestImport::query()->toBase()->update(['updated_at' => now()->subMinutes(GuestImport::STALE_AFTER_MINUTES + 1)]);

        $this->import($event, "name\nLate Guest\n")->assertSessionHasNoErrors()->assertSessionHas('import_created', 1);
    }

    public function test_a_file_that_breaks_part_way_marks_the_import_failed_with_what_was_added(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 1]);
        $event = $this->event();
        $path = $this->xlsx([['name'], ['A'], ['B'], ['C']]);

        $this->upload($event, new UploadedFile($path, 'guests.xlsx', null, null, true));
        $record = GuestImport::query()->sole();
        Storage::disk(GuestImport::DISK)->put($record->path, 'no longer a workbook');

        (new ImportEventGuests($record->id, $record->path))->handle();

        $record->refresh();
        $this->assertSame(GuestImport::STATUS_FAILED, $record->status);
        $this->assertSame(ImportEventGuests::stoppedMessage($record), $record->failure_message);
        $this->assertSame(0, $event->guests()->count());

        $this->get(route('events.guests.import.show', ['event' => $event, 'guestImport' => $record]))
            ->assertOk()
            ->assertSee('The import stopped before it could add anyone.');
    }

    public function test_an_import_for_a_deleted_event_fails_and_removes_the_file(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 3]);
        $event = $this->event();

        $this->import($event, $this->csvRows(5));
        $record = GuestImport::query()->sole();
        $path = $record->path;
        $event->delete();

        (new ImportEventGuests($record->id, $path))->handle();

        $this->assertSame(GuestImport::STATUS_FAILED, $record->fresh()->status);
        Storage::disk(GuestImport::DISK)->assertMissing($path);
        $this->assertSame(0, Guest::query()->where('event_id', $event->id)->count());
    }

    public function test_a_second_pick_up_of_a_running_job_does_not_fail_it(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 3]);
        $event = $this->event();

        $this->import($event, $this->csvRows(5));
        $record = GuestImport::query()->sole();
        $record->forceFill(['status' => GuestImport::STATUS_PROCESSING])->save();

        $job = new ImportEventGuests($record->id, $record->path);
        $job->failed(new MaxAttemptsExceededException('picked up twice'));
        $this->assertSame(GuestImport::STATUS_PROCESSING, $record->fresh()->status);

        $job->failed(new \RuntimeException('worker died'));
        $this->assertSame(GuestImport::STATUS_FAILED, $record->fresh()->status);
    }

    public function test_another_hosts_import_cannot_be_viewed(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 3]);
        $event = $this->event();
        $this->import($event, $this->csvRows(5));
        $record = GuestImport::query()->sole();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get(route('events.guests.import.show', ['event' => $event, 'guestImport' => $record]))->assertForbidden();

        $otherEvent = $this->event($stranger);
        $this->actingAs($stranger)->get(route('events.guests.import.show', ['event' => $otherEvent, 'guestImport' => $record]))->assertNotFound();
    }

    // ── API ──────────────────────────────────────────────────────────────────

    public function test_the_api_reports_skipped_rows_on_a_small_file(): void
    {
        $event = $this->event();

        $this->withHeader('Authorization', 'Bearer '.$event->user->createToken('t')->plainTextToken)
            ->post("/api/v1/host/events/{$event->id}/guests/import", [
                'file' => UploadedFile::fake()->createWithContent('guests.csv', "name\nMwila\nMwila\n"),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('skipped', 1)
            ->assertJsonPath('skipped_rows.0', ['row' => 3, 'message' => 'it repeats row 2.']);
    }

    public function test_the_api_queues_a_big_file_and_reports_it_through_the_status_url(): void
    {
        Queue::fake();
        config(['events.guest_import.sync_max_rows' => 3]);
        $event = $this->event();
        $token = $event->user->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/v1/host/events/{$event->id}/guests/import", [
                'file' => UploadedFile::fake()->createWithContent('guests.csv', $this->csvRows(5)),
            ], ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('status', 'queued');

        $record = GuestImport::query()->sole();
        $statusUrl = $response->json('status_url');
        $this->assertSame(route('api.v1.host.events.guests.import.show', ['event' => $event, 'guestImport' => $record]), $statusUrl);

        (new ImportEventGuests($record->id, $record->path))->handle();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson($statusUrl)
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('finished', true)
            ->assertJsonPath('created', 5)
            ->assertJsonPath('total_rows', 5)
            ->assertJsonPath('file_name', 'guests.csv');
    }

    public function test_the_api_refuses_a_file_without_a_name_column(): void
    {
        $event = $this->event();

        $this->withHeader('Authorization', 'Bearer '.$event->user->createToken('t')->plainTextToken)
            ->post("/api/v1/host/events/{$event->id}/guests/import", [
                'file' => UploadedFile::fake()->createWithContent('guests.csv', "email\na@example.test\n"),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }
}
