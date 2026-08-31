<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Supply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SupplierLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->admin()->create());
    }

    public function test_admin_can_create_a_supplier(): void
    {
        $this->post(route('suppliers.store'), [
            'name' => 'Pak Lubricants',
            'contact_person' => 'Ali Raza',
            'phone' => '03001234567',
            'email' => 'accounts@example.com',
            'address' => 'Lahore',
        ])->assertRedirect();

        $this->assertDatabaseHas('suppliers', ['name' => 'Pak Lubricants', 'contact_person' => 'Ali Raza']);
    }

    public function test_supplier_list_renders_ledger_totals(): void
    {
        $supply = Supply::factory()->create(['total_amount' => '3000.00']);
        SupplierPayment::factory()->for($supply)->create(['amount' => '1200.00']);

        $this->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee($supply->supplier->name)
            ->assertSee('3,000.00')
            ->assertSee('1,800.00');
    }

    public function test_manager_cannot_access_supplier_accounts(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get(route('suppliers.index'))->assertForbidden();
        $this->actingAs($manager)->post(route('suppliers.store'), ['name' => 'Hidden'])->assertForbidden();

        $this->assertDatabaseMissing('suppliers', ['name' => 'Hidden']);
    }

    public function test_each_supply_is_saved_separately_with_a_private_bill_image(): void
    {
        Storage::fake('local');
        $supplier = Supplier::factory()->create();

        $this->post(route('suppliers.supplies.store', $supplier), [
            'received_at' => now()->toDateString(),
            'reference_number' => 'INV-441',
            'items_received' => '20 oil filters and 4 drums of 10W-40',
            'total_amount' => '75000.50',
            'bill_image' => UploadedFile::fake()->image('bill.jpg'),
        ])->assertRedirect();

        $supply = Supply::sole();

        $this->assertSame($supplier->id, $supply->supplier_id);
        $this->assertSame('75000.50', $supply->total_amount);
        Storage::disk('local')->assertExists($supply->bill_image_path);
    }

    public function test_non_image_bill_upload_is_rejected(): void
    {
        Storage::fake('local');
        $supplier = Supplier::factory()->create();

        $this->post(route('suppliers.supplies.store', $supplier), [
            'received_at' => now()->toDateString(),
            'items_received' => 'Oil filters',
            'total_amount' => '1000.00',
            'bill_image' => UploadedFile::fake()->create('bill.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('bill_image');

        $this->assertDatabaseCount('supplies', 0);
    }

    #[TestWith(['cash'])]
    #[TestWith(['online'])]
    #[TestWith(['card'])]
    public function test_payment_history_accepts_each_supported_method(string $method): void
    {
        $supply = Supply::factory()->create(['total_amount' => '1000.00']);

        $this->post(route('suppliers.supplies.payments.store', [$supply->supplier, $supply]), [
            'amount' => '250.00',
            'method' => $method,
            'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'reference_number' => 'TX-100',
        ])->assertRedirect();

        $payment = SupplierPayment::sole();
        $this->assertSame($method, $payment->method->value);
        $this->assertSame(auth()->id(), $payment->user_id);
        $this->assertDatabaseHas('expenses', [
            'supplier_payment_id' => $payment->id,
            'category' => 'shop_supplies',
            'payment_method' => $method,
            'amount' => '250.00',
        ]);
        $this->assertSame($payment->id, Expense::sole()->supplierPayment->id);
    }

    public function test_payment_receipt_is_stored_privately_and_listed_in_history(): void
    {
        Storage::fake('local');
        $supply = Supply::factory()->create(['total_amount' => '3000.00']);

        $this->post(route('suppliers.supplies.payments.store', [$supply->supplier, $supply]), [
            'amount' => '1200.00',
            'method' => PaymentMethod::Online->value,
            'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
            'receipt_image' => UploadedFile::fake()->image('receipt.png'),
        ])->assertRedirect();

        $payment = SupplierPayment::sole();
        Storage::disk('local')->assertExists($payment->receipt_image_path);

        $this->get(route('suppliers.supplies.show', [$supply->supplier, $supply]))
            ->assertOk()->assertSee('1,200.00')->assertSee('1,800.00')->assertSee('View receipt');
    }

    public function test_payment_cannot_exceed_the_remaining_balance(): void
    {
        $supply = Supply::factory()->create(['total_amount' => '1000.00']);
        SupplierPayment::factory()->for($supply)->create(['amount' => '800.00']);

        $this->post(route('suppliers.supplies.payments.store', [$supply->supplier, $supply]), [
            'amount' => '250.00', 'method' => 'cash', 'paid_at' => now()->subMinute()->format('Y-m-d H:i:s'),
        ])->assertSessionHasErrors('amount');

        $this->assertSame(1, $supply->payments()->count());
    }

    public function test_a_supplier_with_financial_history_cannot_be_deleted(): void
    {
        $supply = Supply::factory()->create();

        $this->delete(route('suppliers.destroy', $supply->supplier))->assertSessionHasErrors('supplier');

        $this->assertModelExists($supply->supplier);
        $this->assertModelExists($supply);
    }

    public function test_supply_and_receipt_routes_reject_records_from_another_supplier(): void
    {
        $first = Supplier::factory()->create();
        $secondSupply = Supply::factory()->create();
        $payment = SupplierPayment::factory()->for($secondSupply)->create();

        $this->get(route('suppliers.supplies.show', [$first, $secondSupply]))->assertNotFound();
        $this->get(route('suppliers.supplies.payments.receipt', [$first, $secondSupply, $payment]))->assertNotFound();
    }

    public function test_private_evidence_requires_supplier_permission_and_disables_mime_sniffing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('supplier-bills/bill.jpg', 'image bytes');
        $supply = Supply::factory()->create(['bill_image_path' => 'supplier-bills/bill.jpg']);

        $this->get(route('suppliers.supplies.bill', [$supply->supplier, $supply]))
            ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs(User::factory()->manager()->create())
            ->get(route('suppliers.supplies.bill', [$supply->supplier, $supply]))->assertForbidden();
    }

    public function test_supplier_financial_writes_are_added_to_the_activity_log(): void
    {
        $supplier = Supplier::create(['name' => 'Audit Supplier']);
        $supply = $supplier->supplies()->create([
            'received_at' => now(), 'items_received' => 'Oil drums', 'total_amount' => '5000.00',
        ]);
        $payment = new SupplierPayment(['amount' => '1000.00', 'method' => 'cash', 'paid_at' => now()]);
        $payment->user()->associate(auth()->user());
        $supply->payments()->save($payment);

        $this->assertTrue(ActivityLog::where('action', 'supplier.created')->exists());
        $this->assertTrue(ActivityLog::where('action', 'supply.created')->exists());
        $this->assertTrue(ActivityLog::where('action', 'supplier_payment.created')->exists());
    }
}
