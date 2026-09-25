<?php

namespace App\Services\AI;

/**
 * Indonesian word lists for the offline classifier.
 *
 * Kept as plain data in one place so a non-programmer can extend it: when the
 * team spots a slang term the classifier keeps missing, adding it here is a
 * one-line change with no API involved.
 *
 * Deliberately includes the informal register (gk, bgt, gaje, php) because
 * social comments are not written in formal Indonesian.
 */
class Lexicon
{
    /** Slang → standard, applied before matching so one entry covers both spellings. */
    public const NORMALISE = [
        'gk' => 'gak', 'ga' => 'gak', 'gaa' => 'gak', 'nggak' => 'gak', 'ngga' => 'gak',
        'enggak' => 'gak', 'tdk' => 'tidak', 'tak' => 'tidak', 'g' => 'gak',
        'bgt' => 'banget', 'bngt' => 'banget', 'bgtt' => 'banget',
        'yg' => 'yang', 'dgn' => 'dengan', 'utk' => 'untuk', 'krn' => 'karena',
        'gmn' => 'gimana', 'gmna' => 'gimana', 'bgmn' => 'bagaimana',
        'brp' => 'berapa', 'brapa' => 'berapa', 'kpn' => 'kapan',
        'dmn' => 'dimana', 'dmna' => 'dimana', 'knp' => 'kenapa', 'knapa' => 'kenapa',
        'sy' => 'saya', 'ak' => 'aku', 'aq' => 'aku', 'gw' => 'saya', 'gue' => 'saya',
        'sdh' => 'sudah', 'udh' => 'sudah', 'udah' => 'sudah', 'blm' => 'belum', 'blom' => 'belum',
        'bs' => 'bisa', 'bsa' => 'bisa', 'jd' => 'jadi', 'jgn' => 'jangan',
        'trs' => 'terus', 'tp' => 'tapi', 'tpi' => 'tapi', 'sm' => 'sama',
        'info' => 'info', 'infoo' => 'info', 'mantul' => 'mantap', 'kren' => 'keren',
        'bagusss' => 'bagus', 'jelek' => 'jelek', 'jlek' => 'jelek',
        'mahal' => 'mahal', 'murce' => 'murah', 'ribet' => 'ribet',
        'php' => 'php', 'gaje' => 'gajelas', 'ngaco' => 'ngaco',
    ];

    /** Words that make a message read positively. */
    public const POSITIVE = [
        'bagus', 'baik', 'keren', 'mantap', 'mantul', 'hebat', 'luar biasa', 'top',
        'suka', 'senang', 'seneng', 'puas', 'terbaik', 'rekomendasi', 'recommended',
        'membantu', 'bermanfaat', 'bermutu', 'berkualitas', 'profesional',
        'terima kasih', 'makasih', 'thanks', 'nuhun', 'matur nuwun',
        'sukses', 'semangat', 'salut', 'bangga', 'inspiratif', 'inspiring',
        'ramah', 'cepat', 'jelas', 'lengkap', 'mudah', 'gampang', 'terjangkau', 'murah',
        'alhamdulillah', 'barakallah', 'amin', 'aamiin', 'lancar', 'sehat',
        'love', 'cinta', 'favorit', 'juara', 'worth it', 'legend',
        // Derived and colloquial forms — the lexicon matches whole words, so
        // "menginspirasi" is not found by having "inspirasi" in the list.
        'menginspirasi', 'terinspirasi', 'inspirasi', 'menyenangkan',
        'seru', 'asik', 'asyik', 'lucu', 'wow', 'wah', 'ganteng', 'cantik',
        'indah', 'megah', 'nyaman', 'bersih', 'rapi', 'amanah', 'terpercaya',
        'semoga', 'doakan', 'ditunggu', 'nunggu', 'gas', 'setuju', 'benar',
        'bagusnya', 'kerennya', 'mantapnya', 'sipp', 'sip', 'oke', 'ok',
    ];

    /** Words that make a message read negatively. */
    public const NEGATIVE = [
        'jelek', 'buruk', 'parah', 'payah', 'kacau', 'berantakan', 'amburadul',
        'kecewa', 'mengecewakan', 'menyesal', 'nyesel', 'sedih', 'marah', 'kesal', 'kesel',
        'lambat', 'lelet', 'lama banget', 'ribet', 'susah', 'sulit', 'rumit', 'bertele-tele',
        'mahal', 'kemahalan', 'gajelas', 'ngaco', 'ngawur', 'asal-asalan', 'asal asalan',
        'bohong', 'boong', 'dusta', 'palsu', 'hoax', 'hoaks', 'php', 'omong kosong',
        'sampah', 'bangkrut', 'rugi', 'merugikan', 'zonk', 'bodoh', 'goblok', 'tolol',
        'tidak jelas', 'tidak becus', 'tidak profesional', 'tidak sesuai', 'tidak niat',
        'gak jelas', 'gak becus', 'gak guna', 'gak berguna', 'gak mutu', 'gak worth',
        'complain', 'komplain', 'protes', 'keluhan', 'mengeluh',
        'error', 'gangguan', 'bermasalah', 'rusak', 'gagal', 'ditolak',
        'diabaikan', 'tidak direspon', 'gak direspon', 'gak dibales', 'tidak dibalas',
    ];

    /** Negators that flip the polarity of the word or two that follow. */
    public const NEGATORS = ['gak', 'tidak', 'bukan', 'belum', 'jangan', 'kurang', 'tanpa'];

    /** Intensifiers — presence raises confidence but not polarity. */
    public const INTENSIFIERS = ['banget', 'sangat', 'sekali', 'parah', 'bener', 'benar', 'amat', 'super'];

    /**
     * Words that mark a genuine question, beyond a bare "?".
     *
     * Vocatives are NOT here on purpose — see VOCATIVES. "Mantap kakak" is
     * praise, and treating "kakak" as a question marker mislabelled every
     * friendly comment in the inbox.
     */
    public const QUESTION_MARKERS = [
        'gimana', 'bagaimana', 'apakah', 'berapa', 'kapan', 'dimana', 'kemana',
        'kenapa', 'mengapa', 'siapa', 'bisakah', 'bolehkah', 'adakah', 'apa',
        'mau tanya', 'nanya', 'tanya', 'bertanya', 'izin bertanya', 'nanyak',
        'info dong', 'minta info', 'mohon info', 'caranya', 'gimana caranya',
        'syarat', 'persyaratan', 'prosedur', 'jadwal', 'brosur',
    ];

    /**
     * Ways people address the admin. On their own they mean nothing; paired
     * with a question marker they raise confidence that it really is a query.
     */
    public const VOCATIVES = [
        'min', 'admin', 'kak', 'kakak', 'bang', 'abang', 'mas', 'mbak',
        'pak', 'bu', 'bapak', 'ibu', 'sis', 'gan',
    ];

    /** Signals of someone who might actually enrol. */
    public const LEAD_MARKERS = [
        'mau daftar', 'ingin daftar', 'pengen daftar', 'cara daftar', 'pendaftaran',
        'minat', 'berminat', 'tertarik', 'mau kuliah', 'pengen kuliah', 'ingin kuliah',
        'biaya kuliah', 'spp', 'ukt', 'beasiswa', 'jurusan', 'prodi', 'program studi',
        'kelas karyawan', 'alih jenjang', 'transfer', 'nomor saya', 'wa saya',
        'hubungi saya', 'chat saya', 'kirim info', 'minta info',
    ];

    /** Promo, gambling and bot noise — filtered out before it reaches the LLM. */
    public const SPAM_MARKERS = [
        'follow back', 'folback', 'fol back', 'followback', 'f4f', 'like4like',
        'cek dm', 'cek profil saya', 'kunjungi profil', 'link di bio', 'klik link',
        'slot', 'gacor', 'maxwin', 'judi', 'togel', 'situs', 'depo', 'wd lancar',
        'jual', 'jasa', 'promo', 'diskon', 'order', 'harga murah', 'open po',
        'pinjaman', 'pinjol', 'dana cepat', 'tanpa jaminan', 'bunga rendah',
        'crypto', 'trading', 'binary', 'investasi bodong', 'profit harian',
    ];

    /** Complaint-shaped phrases: negative, but about service rather than reputation. */
    public const COMPLAINT_MARKERS = [
        'tidak dibalas', 'gak dibales', 'tidak direspon', 'gak direspon',
        'sudah lama', 'udah lama', 'lama ditunggu', 'belum ada kabar', 'belum diproses',
        'tolong diperbaiki', 'mohon diperbaiki', 'tolong dicek', 'mohon dicek',
        'error', 'tidak bisa diakses', 'gak bisa dibuka', 'website down',
        'antri', 'antrian', 'dipersulit', 'dipingpong', 'muter-muter',
    ];

    /**
     * Every list flattened for a quick "does this text contain any known word"
     * check. Cached per process because the classifier calls it per message.
     *
     * @return array<int, string>
     */
    public static function allSentimentWords(): array
    {
        static $all = null;

        return $all ??= array_merge(self::POSITIVE, self::NEGATIVE);
    }
}
