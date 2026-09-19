<?php

namespace Tests\Feature;

use App\Models\IntegrationApiKey;
use App\Models\Product;
use App\Models\ProductVariation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the "Custom API" integration exactly the way an external
 * system (a tenant's own POS/e-commerce platform, or a future Shopify
 * connector reusing the same push contract) actually calls it: an
 * X-Integration-Key header, no Sanctum session.
 */
class CustomIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_request_with_no_key_is_rejected(): void
    {
        $this->getJson('/api/v1/integrations/products')->assertStatus(401);
    }

    public function test_a_request_with_an_unknown_key_is_rejected(): void
    {
        $this->withHeaders(['X-Integration-Key' => 'int_doesnotexist'])
            ->getJson('/api/v1/integrations/products')
            ->assertStatus(401);
    }

    public function test_a_request_with_an_inactive_key_is_rejected(): void
    {
        $key = IntegrationApiKey::factory()->create(['is_active' => false]);

        $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->getJson('/api/v1/integrations/products')
            ->assertStatus(401);
    }

    public function test_a_key_without_the_required_scope_is_rejected(): void
    {
        $key = IntegrationApiKey::factory()->create(['scopes' => ['orders:write']]);

        $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->getJson('/api/v1/integrations/products')
            ->assertStatus(403);
    }

    public function test_listing_products_returns_the_tenants_catalog(): void
    {
        $key = IntegrationApiKey::factory()->create();
        Product::factory()->count(3)->create(['tenant_id' => $key->tenant_id]);
        Product::factory()->count(2)->create(); // another tenant — must not appear

        $response = $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->getJson('/api/v1/integrations/products');

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_syncing_a_product_by_external_id_creates_then_updates_the_same_row(): void
    {
        $key = IntegrationApiKey::factory()->create();

        $create = $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->postJson('/api/v1/integrations/products/sync', [
                'products' => [[
                    'external_id' => 'shopify-123',
                    'name' => 'Blue Widget',
                    'quantity' => 10,
                    'unit_price' => 9.99,
                ]],
            ]);

        $create->assertOk();
        $this->assertSame(1, $create->json('created'));
        $this->assertDatabaseHas('products', [
            'tenant_id' => $key->tenant_id,
            'external_id' => 'shopify-123',
            'quantity' => 10,
        ]);

        // Same external_id again, different quantity — must update the
        // existing row, not create a second product for the same item.
        $update = $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->postJson('/api/v1/integrations/products/sync', [
                'products' => [[
                    'external_id' => 'shopify-123',
                    'name' => 'Blue Widget',
                    'quantity' => 7,
                    'unit_price' => 9.99,
                ]],
            ]);

        $update->assertOk();
        $this->assertSame(1, $update->json('updated'));
        $this->assertSame(1, Product::where('tenant_id', $key->tenant_id)->count());
        $this->assertDatabaseHas('products', [
            'tenant_id' => $key->tenant_id,
            'external_id' => 'shopify-123',
            'quantity' => 7,
        ]);
    }

    public function test_syncing_an_order_by_external_product_id_creates_a_sale_and_deducts_stock(): void
    {
        $key = IntegrationApiKey::factory()->create();
        $product = Product::factory()->create([
            'tenant_id' => $key->tenant_id,
            'external_id' => 'shopify-123',
            'quantity' => 50,
        ]);

        $response = $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->postJson('/api/v1/integrations/orders/sync', [
                'orders' => [[
                    'external_order_id' => 'shopify-order-1',
                    'items' => [[
                        'external_product_id' => 'shopify-123',
                        'quantity' => 3,
                        'unit_price' => 9.99,
                    ]],
                ]],
            ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('created'));
        $this->assertEquals(47, $product->fresh()->quantity);
    }

    public function test_syncing_a_product_with_variations_upserts_each_variant_by_external_id(): void
    {
        $key = IntegrationApiKey::factory()->create();

        $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->postJson('/api/v1/integrations/products/sync', [
                'products' => [[
                    'external_id' => 'shopify-shirt',
                    'name' => 'T-Shirt',
                    'quantity' => 0,
                    'unit_price' => 20,
                    'variations' => [
                        ['external_id' => 'shopify-shirt-small', 'name' => 'Small', 'quantity' => 5],
                        ['external_id' => 'shopify-shirt-large', 'name' => 'Large', 'quantity' => 8],
                    ],
                ]],
            ])->assertOk();

        $product = Product::where('tenant_id', $key->tenant_id)->where('external_id', 'shopify-shirt')->first();
        $this->assertCount(2, $product->variations);
        $this->assertDatabaseHas('product_variations', ['external_id' => 'shopify-shirt-small', 'quantity' => 5]);

        // Re-sync with a changed quantity — must update the same variant
        // row, not create a duplicate.
        $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->postJson('/api/v1/integrations/products/sync', [
                'products' => [[
                    'external_id' => 'shopify-shirt',
                    'name' => 'T-Shirt',
                    'quantity' => 0,
                    'unit_price' => 20,
                    'variations' => [
                        ['external_id' => 'shopify-shirt-small', 'name' => 'Small', 'quantity' => 2],
                    ],
                ]],
            ])->assertOk();

        $this->assertCount(2, $product->fresh()->variations);
        $this->assertDatabaseHas('product_variations', ['external_id' => 'shopify-shirt-small', 'quantity' => 2]);
    }

    public function test_syncing_an_order_against_a_specific_variant_moves_both_variant_and_product_stock(): void
    {
        $key = IntegrationApiKey::factory()->create();
        $product = Product::factory()->create([
            'tenant_id' => $key->tenant_id,
            'external_id' => 'shopify-shirt',
            'quantity' => 20,
        ]);
        $variation = ProductVariation::factory()->create([
            'tenant_id' => $key->tenant_id,
            'product_id' => $product->id,
            'external_id' => 'shopify-shirt-small',
            'quantity' => 5,
        ]);

        $this->withHeaders(['X-Integration-Key' => $key->public_key])
            ->postJson('/api/v1/integrations/orders/sync', [
                'orders' => [[
                    'items' => [[
                        'external_product_id' => 'shopify-shirt',
                        'external_variation_id' => 'shopify-shirt-small',
                        'quantity' => 2,
                        'unit_price' => 20,
                    ]],
                ]],
            ])->assertOk();

        $this->assertEquals(3, $variation->fresh()->quantity);
        $this->assertEquals(18, $product->fresh()->quantity);
    }
}
