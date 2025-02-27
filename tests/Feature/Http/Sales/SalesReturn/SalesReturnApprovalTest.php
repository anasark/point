<?php

namespace Tests\Feature\Http\Sales\SalesReturn;

use Tests\TestCase;

use App\Model\Form;
use App\Model\Sales\SalesReturn\SalesReturn;

class SalesReturnApprovalTest extends TestCase
{
    use SalesReturnSetup;

    public static $path = '/api/v1/sales/return';

    private $previousSalesReturnData;

    /** @test */
    public function success_create_sales_return($isFirstCreate = true)
    {
        $data = $this->getDummyData();
        
        if($isFirstCreate) {
            $this->setRole();
            $this->previousSalesReturnData = $data;
        }

        $response = $this->json('POST', self::$path, $data, $this->headers);

        $response->assertStatus(201);
        $this->assertDatabaseHas('forms', [
            'id' => $response->json('data.form.id'),
            'number' => $response->json('data.form.number'),
            'approval_status' => 0,
            'done' => 0,
        ], 'tenant');
    }

    /** @test */
    public function unauthorized_approve_sales_return()
    {
        $this->success_create_sales_return();

        $this->unsetUserRole();

        $salesReturn = SalesReturn::orderBy('id', 'asc')->first();

        $response = $this->json('POST', self::$path . '/' . $salesReturn->id . '/approve', [], $this->headers);

        $response->assertStatus(500)
            ->assertJson([
                "code" => 0,
                "message" => "There is no permission named `approve sales return` for guard `api`."
            ]);
    }

    /** @test */
    public function success_approve_sales_return()
    {
        $this->success_create_sales_return();

        $salesReturn = SalesReturn::orderBy('id', 'asc')->first();

        $response = $this->json('POST', self::$path . '/' . $salesReturn->id . '/approve', [], $this->headers);
        $response->assertStatus(200);
        $subTotal = $response->json('data.amount') - $response->json('data.tax');
        $this->assertDatabaseHas('forms', [
            'id' => $response->json('data.form.id'),
            'number' => $response->json('data.form.number'),
            'approval_status' => 1
        ], 'tenant');
        $this->assertDatabaseHas('user_activities', [
            'number' => $response->json('data.form.number'),
            'table_id' => $response->json('data.id'),
            'table_type' => 'SalesReturn',
            'activity' => 'Approved'
        ], 'tenant');
        $this->assertDatabaseHas('forms', [
            'id' => $response->json('data.form.id'),
            'number' => $response->json('data.form.number'),
            'approval_status' => 1
        ], 'tenant');
        $this->assertDatabaseHas('journals', [
            'form_id' => $response->json('data.form.id'),
            'chart_of_account_id' => $this->arCoa->id,
            'credit' => $response->json('data.amount').'.000000000000000000000000000000'
        ], 'tenant');
        $this->assertDatabaseHas('journals', [
            'form_id' => $response->json('data.form.id'),
            'chart_of_account_id' => $this->salesIncomeCoa->id,
            'debit' => $subTotal.'.000000000000000000000000000000'
        ], 'tenant');
        $this->assertDatabaseHas('journals', [
            'form_id' => $response->json('data.form.id'),
            'chart_of_account_id' => $this->taxCoa->id,
            'debit' => $response->json('data.tax').'.000000000000000000000000000000'
        ], 'tenant');
    }

    /** @test */
    public function unauthorized_reject_sales_return()
    {
        $this->success_create_sales_return();

        $this->unsetUserRole();

        $salesReturn = SalesReturn::orderBy('id', 'asc')->first();

        $response = $this->json('POST', self::$path . '/' . $salesReturn->id . '/reject', [], $this->headers);

        $response->assertStatus(500)
            ->assertJson([
                "code" => 0,
                "message" => "There is no permission named `approve sales return` for guard `api`."
            ]);
    }

    /** @test */
    public function invalid_reject_sales_return()
    {
        $this->success_create_sales_return();

        $salesReturn = SalesReturn::orderBy('id', 'asc')->first();

        $response = $this->json('POST', self::$path . '/' . $salesReturn->id . '/reject', [], $this->headers);

        $response->assertStatus(422);
    }

    /** @test */
    public function success_reject_sales_return()
    {
        $this->success_create_sales_return();

        $salesReturn = SalesReturn::orderBy('id', 'asc')->first();
        $data['reason'] = $this->faker->text(200);

        $response = $this->json('POST', self::$path . '/' . $salesReturn->id . '/reject', $data, $this->headers);

        $response->assertStatus(200);
        $this->assertDatabaseHas('forms', [
            'id' => $response->json('data.form.id'),
            'number' => $response->json('data.form.number'),
            'approval_status' => -1,
            'done' => 0,
        ], 'tenant');
        $this->assertDatabaseHas('user_activities', [
            'number' => $response->json('data.form.number'),
            'table_id' => $response->json('data.id'),
            'table_type' => 'SalesReturn',
            'activity' => 'Rejected'
        ], 'tenant');
    }

    /** @test */
    public function success_read_approval_sales_return()
    {
        $this->success_create_sales_return();

        $data = [
            'join' => 'form,customer,items,item',
            'fields' => 'sales_return.*',
            'sort_by' => '-form.number',
            'group_by' => 'form.id',
            'filter_form'=>'notArchived;null',
            'filter_like'=>'{}',
            'filter_date_min'=>'{"form.date":"2022-05-01 00:00:00"}',
            'filter_date_max'=>'{"form.date":"2022-05-17 23:59:59"}',
            'includes'=>'form;customer;warehouse;items.item;items.allocation',
            'limit'=>10,
            'page' => 1
        ];

        $response = $this->json('GET', self::$path . '/approval', $data, $this->headers);
        
        $response->assertStatus(200);
    }

    /** @test */
    public function success_send_approval_sales_return()
    {
        $this->success_create_sales_return();

        $salesReturn = SalesReturn::orderBy('id', 'asc')->first();
        $data['ids'][] = ['id' => $salesReturn->id];

        $response = $this->json('POST', self::$path . '/approval/send', $data, $this->headers);

        $response->assertStatus(200);
    }

    /** @test */
    public function success_send_multiple_approval_sales_return()
    {
        $this->success_create_sales_return();

        $this->success_create_sales_return($isFirstCreate = false);
        $salesReturn = SalesReturn::orderBy('id', 'desc')->first();
        $salesReturn->form->cancellation_status = 0;
        $salesReturn->form->close_status = null;
        $salesReturn->form->save();

        $data['ids'] = SalesReturn::get()
            ->pluck('id')
            ->map(function ($id) { return ['id' => $id]; })
            ->toArray();

        $response = $this->json('POST', self::$path . '/approval/send', $data, $this->headers);

        $response->assertStatus(200);
    }
}