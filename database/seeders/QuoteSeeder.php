<?php

namespace Database\Seeders;

use App\Models\Text;
use App\Models\Language;
use Illuminate\Database\Seeder;

class QuoteSeeder extends Seeder
{
    /**
     * Seed beberapa kutipan untuk mode 'quote'.
     * Mode time/words membaca wordlist dari JSON (lihat blueprint 6.5),
     * sedangkan tabel `texts` dipakai engine HANYA untuk mode quote.
     */
    public function run(): void
    {
        $id = Language::where('code', 'id')->first();
        $en = Language::where('code', 'en')->first();

        $quotes = [
            ['lang' => $id, 'difficulty' => 'easy', 'author' => 'Pramoedya Ananta Toer',
                'content' => 'Orang boleh pandai setinggi langit, tapi selama ia tidak menulis, ia akan hilang di dalam masyarakat dan dari sejarah.'],
            ['lang' => $id, 'difficulty' => 'medium', 'author' => 'Tan Malaka',
                'content' => 'Idealisme adalah kemewahan terakhir yang hanya dimiliki oleh pemuda.'],
            ['lang' => $id, 'difficulty' => 'medium', 'author' => 'Soekarno',
                'content' => 'Gantungkan cita-citamu setinggi langit. Bermimpilah setinggi langit. Jika engkau jatuh, engkau akan jatuh di antara bintang-bintang.'],
            ['lang' => $en, 'difficulty' => 'easy', 'author' => 'Confucius',
                'content' => 'It does not matter how slowly you go as long as you do not stop.'],
            ['lang' => $en, 'difficulty' => 'medium', 'author' => 'Nelson Mandela',
                'content' => 'The greatest glory in living lies not in never falling, but in rising every time we fall.'],
            ['lang' => $en, 'difficulty' => 'hard', 'author' => 'Ralph Waldo Emerson',
                'content' => 'What lies behind us and what lies before us are tiny matters compared to what lies within us.'],
        ];

        foreach ($quotes as $q) {
            if (! $q['lang']) {
                continue;
            }

            Text::create([
                'language_id' => $q['lang']->id,
                'content' => $q['content'],
                'mode' => 'quote',
                'difficulty' => $q['difficulty'],
                'author' => $q['author'],
                'word_count' => str_word_count($q['content']),
            ]);
        }

        $this->command->info('Berhasil menambahkan ' . count($quotes) . ' kutipan ke tabel texts (mode quote).');
    }
}
