<?php

namespace Database\Seeders;

use App\Models\Text;
use App\Models\Language;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class TextSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Pastikan bahasa Indonesia sudah ada di tabel languages
        $language = Language::where('code', 'id')->first();

        if (!$language) {
            $this->command->error("Bahasa dengan kode 'id' tidak ditemukan. Jalankan LanguageSeeder dulu!");
            return;
        }

        // 2. Ambil file JSON
        $path = database_path('data/indonesian.json');
        
        if (!File::exists($path)) {
            $this->command->error("File JSON tidak ditemukan di: $path");
            return;
        }

        $json = File::get($path);
        $data = json_decode($json, true);
        $words = $data['words']; // Monkeytype menyimpan kata di dalam array 'words'

        // 3. Masukkan ke Database
        // Strategi: Karena 'content' di tabel kita adalah TEXT, kita bisa menyimpan 
        // kumpulan kata (misal 50 kata per baris) agar tidak terlalu banyak baris di DB.
        
        $chunks = array_chunk($words, 30); // Kelompokkan per 30 kata

        foreach ($chunks as $chunk) {
            Text::create([
                'language_id' => $language->id,
                'content' => implode(' ', $chunk), // Gabungkan array jadi string
                'mode' => 'wordlist',
                'difficulty' => 'easy', // Default
                'author' => 'Monkeytype',
            ]);
        }

        $this->command->info("Berhasil mengimpor " . count($words) . " kata ke tabel texts.");
    }
}