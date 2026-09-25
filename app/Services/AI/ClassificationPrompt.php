<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;

/**
 * The instruction sent to whichever LLM is configured, and the parser for what
 * comes back.
 *
 * Shared by every remote driver so a wording improvement lands everywhere at
 * once — and so switching provider can never quietly change the meaning of the
 * labels the team has been reading.
 *
 * PRIVACY: build() is the only place a message leaves the application, and it
 * sends the message TEXT ONLY. Handles, real names, phone numbers, contact ids
 * and post links never go out — items are keyed by a positional index and
 * matched back by that index. Do not add identifying fields here.
 */
class ClassificationPrompt
{
    /** Longest message sent for classification; ample for any real comment. */
    public const MAX_CHARS = 600;

    /**
     * Turn caller-keyed texts into the numbered payload and the prompt around
     * it. Returns the prompt plus the index → caller-key map needed to put the
     * answers back where they belong.
     *
     * @param  array<string, string>  $texts
     * @return array{prompt: string, keys: array<int, string>}
     */
    public static function build(array $texts): array
    {
        $keys = array_keys($texts);
        $items = [];

        foreach (array_values($texts) as $i => $text) {
            $items[] = ['i' => $i, 'teks' => mb_substr(trim($text), 0, self::MAX_CHARS)];
        }

        $payload = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $prompt = <<<PROMPT
        Kamu adalah analis media sosial untuk sebuah perguruan tinggi di Indonesia.
        Klasifikasikan setiap komentar/pesan berikut. Bahasa yang dipakai adalah
        bahasa Indonesia, sering informal dan bercampur slang atau bahasa daerah.

        Untuk setiap item, tentukan:

        - sentiment: "positive" | "neutral" | "negative"
        - intent: salah satu dari
            "question"       = bertanya (pendaftaran, biaya, jadwal, syarat, dll)
            "praise"         = memuji atau berterima kasih
            "complaint"      = mengeluhkan layanan yang bisa diperbaiki
            "disparagement"  = menjelekkan / menyerang reputasi institusi
            "spam"           = promosi, judi online, pinjol, follow-back
            "other"          = selain di atas
        - is_urgent: true HANYA jika komentar menyerang reputasi institusi,
          menuduh penipuan/ijazah palsu/tidak terakreditasi, mengancam
          menyebarkan atau memviralkan, menuduh korupsi/pungli, atau melaporkan
          pelecehan/kekerasan. Keluhan layanan biasa BUKAN urgent.
        - urgency_score: 0-100
        - lead_potential: 0-100, seberapa besar kemungkinan orang ini benar-benar
          ingin mendaftar kuliah (menanyakan pendaftaran/biaya/jurusan = tinggi)
        - needs_reply: true jika komentar ini pantas dibalas oleh admin
        - confidence: 0-100, seberapa yakin kamu
        - reason: alasan sangat singkat, maksimal 10 kata, bahasa Indonesia

        Penting:
        - Sarkasme sering terlihat positif tetapi maksudnya negatif — perhatikan.
        - Pujian singkat seperti "keren" atau emoji saja: needs_reply = false.
        - Jangan mengarang. Jika ragu, pakai confidence rendah.

        Balas HANYA dengan satu objek JSON berisi field "hasil", tanpa penjelasan
        dan tanpa blok kode. Setiap elemen di dalamnya wajib punya field "i"
        yang sama persis dengan input.

        Contoh keluaran:
        {"hasil":[{"i":0,"sentiment":"negative","intent":"disparagement","is_urgent":true,"urgency_score":90,"lead_potential":0,"needs_reply":true,"confidence":88,"reason":"Menuduh kampus menipu"}]}

        Data:
        {$payload}
        PROMPT;

        return ['prompt' => $prompt, 'keys' => $keys];
    }

    /**
     * Decode a model's reply into Classifications, keyed back to the caller.
     *
     * Tolerant on purpose: models wrap JSON in code fences, prepend a sentence,
     * or return an object with the array inside. A malformed reply must cost us
     * one batch, not the run.
     *
     * @param  array<int, string>  $keys  index → caller key, from build()
     * @return array<string, Classification>
     */
    public static function parse(?string $raw, array $keys, string $model): array
    {
        $decoded = self::decode($raw);

        if ($decoded === null) {
            return [];
        }

        $out = [];

        foreach ($decoded as $row) {
            if (! is_array($row) || ! isset($row['i'])) {
                continue;
            }

            $key = $keys[(int) $row['i']] ?? null;

            if ($key !== null) {
                $out[$key] = Classification::fromArray($row, $model);
            }
        }

        return $out;
    }

    /**
     * Pull the list of verdicts out of whatever shape came back.
     *
     * The prompt asks for {"hasil":[…]} rather than a bare array because
     * OpenAI-style JSON mode (which Groq enforces) requires the top level to
     * be an OBJECT — asking for an array there makes the model give up and
     * echo the input instead. A bare array is still accepted, since providers
     * without JSON mode return one happily.
     *
     * @return array<int, mixed>|null
     */
    private static function decode(?string $text): ?array
    {
        if (blank($text)) {
            return null;
        }

        $text = trim($text);

        // Strip a ```json … ``` fence if the model added one anyway.
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', $text) ?? $text;
        }

        $decoded = json_decode($text, true);

        // A stray sentence around the JSON — take the outermost array.
        if (! is_array($decoded) && preg_match('/\[.*\]/s', $text, $match)) {
            $decoded = json_decode($match[0], true);
        }

        if (! is_array($decoded)) {
            Log::warning('Klasifikasi: balasan bukan JSON valid — '.mb_substr($text, 0, 300));

            return null;
        }

        // Already a list of verdicts.
        if (array_is_list($decoded)) {
            return $decoded;
        }

        // Wrapped in an object: take "hasil", or the first list inside it if
        // the model chose its own key.
        if (isset($decoded['hasil']) && is_array($decoded['hasil'])) {
            return array_values($decoded['hasil']);
        }

        foreach ($decoded as $value) {
            if (is_array($value) && array_is_list($value)) {
                return $value;
            }
        }

        Log::warning('Klasifikasi: JSON valid tapi tanpa daftar hasil — '.mb_substr($text, 0, 300));

        return null;
    }
}
