<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Language;

class LanguageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $languages = [
            [
                'code' => 'id',
                'name' => 'Indonesian',
            ],
            [
                'code' => 'en',
                'name' => 'English',
            ],
            // Kamu bisa tambahkan bahasa lain di sini nanti
        ];

        foreach ($languages as $lang) {
            Language::create($lang);
        }
        
        $this->command->info('Bahasa Indonesia dan Inggris berhasil ditambahkan.');
    }
}