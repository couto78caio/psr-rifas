<?php

namespace Tests\Feature\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Rifa;
use App\Services\MercadoPago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ao fazer um POST para a rota de pagamento, o sistema deverá criar um pagamento com a resposta
     * do sistema de pagamento
     */
    public function test_salvar_dados_de_pagamento_na_tabela_payment_ao_fazer_requisicao(): void
    {
        $rifa = Rifa::factory(1)->hasOrders(1)->create()->first();
        $order = Order::where('rifa_id', $rifa->id)->first();

        $resultFake = [
            'id' => fake()->randomNumber(),
            'ticket_url' => 'https://example.com',
            'payment_method_id' => 'pix',
            'date_of_expiration' => '2023-07-06T15:48:10.178-04:00',
            'transaction_amount' => 1.00,
            'qr_code' => 'qrcode',
        ];

        $this->mock(MercadoPago::class, function ($mock) use ($resultFake) {
            $mock->shouldReceive('generatePix')->once()
                ->andReturn((object)$resultFake);
        });

        $response = $this->post("/payments", [
            'orderId' => $order->id
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $resultFake['id']
        ]);

        $response->assertLocation("payments/{$resultFake['id']}");
    }

    public function test_caso_o_pagamento_seja_aprovado_atualizar_banco_de_dados(): void
    {
        $rifa = Rifa::factory(1)
            ->has(
                Order::factory(1)
                    ->has(
                        Payment::factory(1)
                    )
            )
            ->create([
                'status' => 'reserved'
            ])
            ->first();

        $payment = $rifa->orders()->first()->payment()->first();

        $date_approved = now();

        $resultFake = [
            'date_approved' => $date_approved,
            'status' => 'approved'
        ];

        $this->mock(MercadoPago::class, function ($mock) use ($resultFake) {
            $mock->shouldReceive('getPayment')->once()
                ->andReturn((object)$resultFake);
        });

        $this->post("/payments/notification", [
            'action' => 'payment.updated',
            'data' => [
                'id' => $payment->id
            ]
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'date_approved' => $date_approved
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $payment->order_id,
            'status' => 'paid'
        ]);
    }
}
