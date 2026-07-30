<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->updateOrCreate(
            ['email' => 'admin@hanuman.style'],
            [
                'name' => 'Admin Hanuman',
                'password' => Hash::make('password'),
            ]
        );

        $branches = [
            ['name' => 'Hanuman Style Centro', 'address' => 'Sucursal Centro', 'is_active' => true],
            ['name' => 'Hanuman Style Norte', 'address' => 'Sucursal Norte', 'is_active' => true],
        ];

        foreach ($branches as $branchData) {
            Branch::query()->updateOrCreate(
                ['name' => $branchData['name']],
                $branchData + [
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]
            );
        }

        $products = [
            ['name' => 'Bebida energética', 'sku' => 'BEB-001', 'unit_price' => 25.00],
            ['name' => 'Vendas', 'sku' => 'IMP-001', 'unit_price' => 80.00],
            ['name' => 'Bucal', 'sku' => 'IMP-002', 'unit_price' => 120.00],
            ['name' => 'Guantes', 'sku' => 'IMP-003', 'unit_price' => 450.00],
        ];

        $branchIds = Branch::query()->pluck('id');

        foreach ($products as $productData) {
            $product = Product::query()->updateOrCreate(
                ['sku' => $productData['sku']],
                $productData + [
                    'is_active' => true,
                    'created_by' => $admin->id,
                    'updated_by' => $admin->id,
                ]
            );

            foreach ($branchIds as $branchId) {
                ProductStock::query()->updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'branch_id' => $branchId,
                    ],
                    [
                        'quantity' => 10,
                        'created_by' => $admin->id,
                        'updated_by' => $admin->id,
                    ]
                );
            }
        }
    }
}
