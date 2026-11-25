<?php

namespace App\Services;

use App\Models\AdmissionRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use RuntimeException;

class ListSigner
{
    public const EMU_HEADER = 'PDEMU-CMS-v1';

    public function mode(): string
    {
        return config('permits.sign_mode') === 'ncalayer' ? 'ncalayer' : 'emulate';
    }

    /** @return array<string, mixed> */
    public function payload(AdmissionRequest $request): array
    {
        $request->loadMissing(['organization', 'customer', 'facility', 'profile.items.type', 'workers.documents']);
        $report = new ReadinessReport($request->organization);
        $rep = $report->forRequest($request);
        $workers = [];
        foreach ($rep['workers'] as $row) {
            $w = $row['worker'];
            $docs = [];
            foreach ($row['result']['items'] as $item) {
                $docs[] = [
                    'type' => $item['name'],
                    'number' => $item['number'],
                    'valid_until' => $item['valid_until'],
                    'group' => $item['group_level'] ? Readiness::roman((int) $item['group_level']) : null,
                ];
            }
            $workers[] = [
                'iin' => $w->iin,
                'name' => $w->fullName(),
                'position' => $w->position.($w->grade ? ', '.$w->grade.' разряд' : ''),
                'documents' => $docs,
            ];
        }

        return [
            'type' => 'admission-list',
            'number' => $request->number,
            'contractor' => ['name' => $request->organization->name, 'bin' => $request->organization->bin],
            'customer' => $request->customer->name,
            'facility' => $request->facility?->name,
            'work_type' => $request->work_type,
            'profile' => $request->profile->name,
            'period' => [CarbonImmutable::parse($request->start_date)->toDateString(), CarbonImmutable::parse($request->end_date)->toDateString()],
            'workers' => $workers,
        ];
    }

    public function payloadText(AdmissionRequest $request): string
    {
        return json_encode($this->payload($request), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }

    public function hash(AdmissionRequest $request): string
    {
        return hash('sha256', $this->payloadText($request));
    }

    private function hmac(string $data, string $bin, string $subject): string
    {
        return hash_hmac('sha256', $data."\n".$bin."\n".$subject, (string) config('app.key'));
    }

    public function emulate(string $dataBase64, User $user): string
    {
        $data = base64_decode($dataBase64, true);
        if ($data === false) {
            throw new RuntimeException('Данные для подписи должны быть в base64');
        }
        $bin = $user->organization?->bin ?? '';
        $subject = $user->name;

        return base64_encode(implode("\n", [
            self::EMU_HEADER,
            $dataBase64,
            'BIN:'.$bin,
            'SUBJECT:'.$subject,
            'SIG:'.$this->hmac($data, $bin, $subject),
        ]));
    }

    /**
     * @return array{mode: string, subject: string}
     */
    public function verify(string $signatureBase64, string $expectedData, string $bin): array
    {
        $raw = base64_decode(trim($signatureBase64), true);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('Подпись не в формате base64');
        }
        if (str_starts_with($raw, self::EMU_HEADER."\n")) {
            if ($this->mode() !== 'emulate') {
                throw new RuntimeException('Эмулированная подпись не принимается: включён режим NCALayer');
            }
            $lines = explode("\n", $raw);
            if (count($lines) !== 5) {
                throw new RuntimeException('Повреждённая подпись');
            }
            $data = base64_decode($lines[1], true);
            $sigBin = substr($lines[2], 4);
            $subject = substr($lines[3], 8);
            $sig = substr($lines[4], 4);
            if ($data !== $expectedData) {
                throw new RuntimeException('Подписан другой список: обновите страницу и подпишите заново');
            }
            if ($sigBin !== $bin) {
                throw new RuntimeException('Подпись выдана для другой организации');
            }
            if (! hash_equals($this->hmac($data, $bin, $subject), $sig)) {
                throw new RuntimeException('Подпись не прошла проверку');
            }

            return ['mode' => 'emulate', 'subject' => $subject.' (эмуляция)'];
        }
        if (ord($raw[0]) !== 0x30) {
            throw new RuntimeException('Подпись не похожа на CMS');
        }

        return ['mode' => 'ncalayer', 'subject' => $this->subjectFromCms($raw) ?? 'владелец ключа ЭЦП'];
    }

    private function subjectFromCms(string $der): ?string
    {
        if (! function_exists('openssl_pkcs7_read')) {
            return null;
        }
        $pem = "-----BEGIN PKCS7-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PKCS7-----\n";
        $certs = [];
        try {
            if (! @openssl_pkcs7_read($pem, $certs) || ! $certs) {
                return null;
            }
            $info = @openssl_x509_parse($certs[0]);
            $cn = $info['subject']['CN'] ?? null;
            $sn = $info['subject']['serialNumber'] ?? null;

            return $cn ? trim($cn.($sn ? ', '.$sn : '')) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
