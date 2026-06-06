<?php

use App\Modules\Delivery\Models\Delivery;

beforeEach(function () {
    seedAccessControl();
});

it('marks POD signed on delivery report when receiver_signature_data exists', function () {
    $delivery = Delivery::factory()->create([
        'receiver_name' => 'Budi Santoso',
        'receiver_signature_data' => validPodSignatureData(),
        'receiver_signature_path' => null,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('reports.delivery'))
        ->assertOk()
        ->assertSee($delivery->delivery_number)
        ->assertSee('Ditandatangani');
});

it('marks POD signed on delivery report for legacy receiver_signature_path', function () {
    $delivery = Delivery::factory()->delivered()->create([
        'receiver_signature_data' => null,
    ]);

    $this->actingAs(superAdmin())
        ->get(route('reports.delivery'))
        ->assertOk()
        ->assertSee($delivery->delivery_number)
        ->assertSee('Ditandatangani');
});

it('shows unsigned POD on delivery report when no signature is stored', function () {
    $delivery = Delivery::factory()->create([
        'receiver_name' => null,
        'receiver_signature_data' => null,
        'receiver_signature_path' => null,
    ]);

    $response = $this->actingAs(superAdmin())
        ->get(route('reports.delivery'))
        ->assertOk()
        ->assertSee($delivery->delivery_number);

    $rowHtml = extractDeliveryReportRowHtml($response->getContent(), $delivery->delivery_number);

    expect($rowHtml)->not->toContain('Ditandatangani');
    expect($rowHtml)->toContain('—');
});

it('exports POD signed YES when receiver_signature_data exists', function () {
    $delivery = Delivery::factory()->create([
        'receiver_name' => 'Budi Santoso',
        'receiver_signature_data' => validPodSignatureData(),
        'receiver_signature_path' => null,
    ]);

    $response = $this->actingAs(userWith(['view_delivery_report', 'export_report']))
        ->get(route('reports.delivery.export'));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain($delivery->delivery_number);
    expect($content)->toContain('YES');
});

it('exports POD signed YES for legacy receiver_signature_path', function () {
    $delivery = Delivery::factory()->delivered()->create([
        'receiver_signature_data' => null,
    ]);

    $response = $this->actingAs(userWith(['view_delivery_report', 'export_report']))
        ->get(route('reports.delivery.export'));

    $response->assertOk();
    $content = $response->streamedContent();
    expect($content)->toContain($delivery->delivery_number);
    expect($content)->toContain('YES');
});

it('exports POD signed NO when delivery has no signature', function () {
    $delivery = Delivery::factory()->create([
        'receiver_signature_data' => null,
        'receiver_signature_path' => null,
    ]);

    $response = $this->actingAs(userWith(['view_delivery_report', 'export_report']))
        ->get(route('reports.delivery.export'));

    $response->assertOk();
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
    $dataLine = collect($lines)->first(fn (string $line) => str_contains($line, $delivery->delivery_number));

    expect($dataLine)->not->toBeNull();
    expect($dataLine)->toContain('NO');
});

function extractDeliveryReportRowHtml(string $html, string $deliveryNumber): string
{
    if (! preg_match('/<tr>[\s\S]*?'.preg_quote($deliveryNumber, '/').'[\s\S]*?<\/tr>/', $html, $matches)) {
        return '';
    }

    return $matches[0];
}
