<?php

namespace Database\Seeders;

use App\Models\Catalog;
use App\Models\User;
use Illuminate\Database\Seeder;

class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = User::query()->where('email', 'admin@hanuman.style')->value('id');

        Catalog::ensureExpenseCategories($adminId);
        Catalog::ensurePaymentMethods($adminId);
    }
}
