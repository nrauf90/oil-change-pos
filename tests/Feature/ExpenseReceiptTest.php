<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExpenseReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->actingAs(User::factory()->admin()->create());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validExpense(array $overrides = []): array
    {
        return array_merge([
            'category' => 'tea_lunch',
            'amount' => '450.75',
            'description' => 'Lunch for the two mechanics',
        ], $overrides);
    }

    public function test_an_expense_is_logged_with_a_receipt_photo_attached(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->image('till-slip.jpg')],
        ]))->assertRedirect(route('expenses.index'));

        $receipt = ExpenseReceipt::sole();

        $this->assertSame(Expense::sole()->id, $receipt->expense_id);
        $this->assertSame('till-slip.jpg', $receipt->original_name);
        $this->assertSame(auth()->id(), $receipt->user_id);
        Storage::disk('local')->assertExists($receipt->path);
    }

    public function test_several_receipts_can_be_attached_to_one_expense(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [
                UploadedFile::fake()->image('fuel.jpg'),
                UploadedFile::fake()->create('parts-invoice.pdf', 20, 'application/pdf'),
            ],
        ]))->assertRedirect();

        $this->assertCount(2, Expense::sole()->receipts);
        $this->assertTrue(ExpenseReceipt::where('original_name', 'parts-invoice.pdf')->sole()->isPdf());
    }

    public function test_an_expense_is_still_logged_with_no_receipt_at_all(): void
    {
        $this->post(route('expenses.store'), $this->validExpense())->assertRedirect();

        $this->assertDatabaseCount('expenses', 1, 'tenant');
        $this->assertDatabaseCount('expense_receipts', 0, 'tenant');
    }

    public function test_a_receipt_is_stored_under_the_tenant_prefix_and_not_by_its_original_name(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->image('till-slip.jpg')],
        ]))->assertRedirect();

        $path = ExpenseReceipt::sole()->path;

        $this->assertStringStartsWith('tenants/', $path);
        $this->assertStringContainsString('/expense-receipts/', $path);
        $this->assertStringNotContainsString('till-slip', $path);
    }

    public function test_an_executable_upload_is_rejected_and_nothing_is_written(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->create('payload.php', 10, 'application/x-php')],
        ]))->assertSessionHasErrors('receipts.0');

        $this->assertDatabaseCount('expenses', 0, 'tenant');
        $this->assertDatabaseCount('expense_receipts', 0, 'tenant');
    }

    public function test_an_oversized_receipt_is_rejected(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->create('huge.jpg', 6144, 'image/jpeg')],
        ]))->assertSessionHasErrors('receipts.0');

        $this->assertDatabaseCount('expenses', 0, 'tenant');
    }

    public function test_more_receipts_than_the_cap_are_rejected(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => array_map(
                fn (int $index) => UploadedFile::fake()->image('slip-'.$index.'.jpg'),
                range(1, 6),
            ),
        ]))->assertSessionHasErrors('receipts');

        $this->assertDatabaseCount('expenses', 0, 'tenant');
    }

    public function test_editing_an_expense_adds_a_receipt_without_dropping_the_existing_ones(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->image('first.jpg')],
        ]))->assertRedirect();

        $expense = Expense::sole();

        $this->put(route('expenses.update', $expense), $this->validExpense([
            'amount' => '500.00',
            'receipts' => [UploadedFile::fake()->image('second.jpg')],
        ]))->assertRedirect();

        $this->assertSame(
            ['first.jpg', 'second.jpg'],
            $expense->refresh()->receipts->pluck('original_name')->all(),
        );
    }

    public function test_a_receipt_is_streamed_back_and_never_served_as_a_guessable_url(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->image('till-slip.jpg')],
        ]))->assertRedirect();

        $receipt = ExpenseReceipt::sole();

        $this->get(route('expenses.receipts.show', [$receipt->expense_id, $receipt]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_a_receipt_cannot_be_read_through_an_expense_it_does_not_belong_to(): void
    {
        $receipt = ExpenseReceipt::factory()->create();
        $otherExpense = Expense::factory()->create();

        $this->get(route('expenses.receipts.show', [$otherExpense, $receipt]))->assertNotFound();
    }

    public function test_a_single_receipt_can_be_removed_and_its_file_goes_with_it(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->image('wrong-slip.jpg')],
        ]))->assertRedirect();

        $receipt = ExpenseReceipt::sole();

        $this->delete(route('expenses.receipts.destroy', [$receipt->expense_id, $receipt]))
            ->assertRedirect(route('expenses.edit', $receipt->expense_id));

        $this->assertDatabaseCount('expense_receipts', 0, 'tenant');
        Storage::disk('local')->assertMissing($receipt->path);
    }

    public function test_deleting_an_expense_takes_its_receipts_and_their_files(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->image('slip.jpg')],
        ]))->assertRedirect();

        $receipt = ExpenseReceipt::sole();

        $this->delete(route('expenses.destroy', $receipt->expense_id))->assertRedirect();

        $this->assertDatabaseCount('expenses', 0, 'tenant');
        $this->assertDatabaseCount('expense_receipts', 0, 'tenant');
        Storage::disk('local')->assertMissing($receipt->path);
    }

    public function test_a_technician_cannot_read_a_receipt(): void
    {
        $receipt = ExpenseReceipt::factory()->create();

        $this->actingAs(User::factory()->technician()->create())
            ->get(route('expenses.receipts.show', [$receipt->expense_id, $receipt]))
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login_from_the_receipt_routes(): void
    {
        $receipt = ExpenseReceipt::factory()->create();

        auth()->logout();

        $this->get(route('expenses.receipts.show', [$receipt->expense_id, $receipt]))
            ->assertRedirect(route('login'));
    }

    public function test_the_expense_list_flags_which_outlays_have_proof(): void
    {
        $this->post(route('expenses.store'), $this->validExpense([
            'receipts' => [UploadedFile::fake()->image('slip.jpg')],
        ]))->assertRedirect();

        $this->get(route('expenses.index'))
            ->assertOk()
            ->assertSee('receipt(s) on file');
    }
}
