<?php

namespace Database\Seeders;

use App\Models\AdmissionRequest;
use App\Models\Customer;
use App\Models\DocumentType;
use App\Models\Facility;
use App\Models\Organization;
use App\Models\RequirementProfile;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerDocument;
use App\Services\ListSigner;
use App\Services\ReadinessReport;
use App\Support\Catalog;
use App\Support\Iin;
use App\Support\SampleScans;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public const PASSWORD = 'portal2026';

    private const ROLE_DOCS = [
        'electric' => ['pb', 'eb', 'height', 'first_aid'],
        'welder' => ['pb', 'fire', 'gas', 'first_aid', 'welder'],
        'mounter' => ['pb', 'height', 'psych', 'first_aid', 'sling', 'eb'],
        'fitter' => ['pb', 'gas', 'confined', 'psych', 'first_aid'],
        'slinger' => ['pb', 'sling', 'height', 'first_aid'],
        'master' => ['pb', 'eb', 'height', 'first_aid', 'fire', 'gas', 'psych'],
        'insulator' => ['pb', 'height', 'first_aid', 'psych'],
    ];

    private const ISSUERS = [
        'med' => 'КГП на ПХВ «Поликлиника №3 г. Павлодара»',
        'psych' => 'КГП «Павлодарский областной центр психического здоровья»',
        'intro' => 'ТОО «Ертіс Монтаж Сервис», служба ОТ',
        'repeat' => 'ТОО «Ертіс Монтаж Сервис», мастер участка',
        'ot' => 'ТОО «Учебный центр Промбезопасность-Павлодар»',
        'pb' => 'ТОО «Учебный центр Промбезопасность-Павлодар»',
        'eb' => 'Комиссия ТОО «Ертіс Монтаж Сервис»',
        'height' => 'ТОО «Учебный центр Промбезопасность-Павлодар»',
        'fire' => 'ТОО «Центр пожарной безопасности Иртыш»',
        'gas' => 'ТОО «Учебный центр Промбезопасность-Павлодар»',
        'confined' => 'ТОО «Учебный центр Промбезопасность-Павлодар»',
        'sling' => 'ТОО «Павлодарский учебно-курсовой комбинат»',
        'welder' => 'Аттестационный центр сварщиков, г. Павлодар',
        'lift' => 'ТОО «Павлодарский учебно-курсовой комбинат»',
        'first_aid' => 'ТОО «Учебный центр Промбезопасность-Павлодар»',
        'ppe' => 'ТОО «Ертіс Монтаж Сервис», кладовая СИЗ',
    ];

    private CarbonImmutable $today;

    private int $seq = 0;

    public function run(): void
    {
        mt_srand(2026);
        $this->today = ReadinessReport::today();

        DB::transaction(function () {
            $org = Organization::create([
                'name' => 'ТОО «Ертіс Монтаж Сервис»',
                'short_name' => 'Ертіс Монтаж Сервис',
                'bin' => '150340012340',
                'city' => 'Павлодар',
                'address' => 'г. Павлодар, Северная промзона, 12',
                'director' => 'Касымов Нурлан Болатович',
                'phone' => '+7 7182 00 00 00',
                'email' => 'office@ertis.test',
                'notify_email' => 'ot@ertis.test',
                'notify_email_enabled' => true,
                'telegram_chat_id' => null,
                'notify_telegram_enabled' => true,
                'notify_thresholds' => [30, 14, 7],
            ]);
            Catalog::installDocumentTypes($org);
            $types = DocumentType::where('organization_id', $org->id)->get()->keyBy('code');

            $head = User::create(['organization_id' => $org->id, 'name' => 'Касымов Нурлан Болатович', 'email' => 'head@ertis.test', 'password' => self::PASSWORD, 'role' => User::HEAD, 'position' => 'Директор']);
            User::create(['organization_id' => $org->id, 'name' => 'Нурпеисова Айгерим Сериковна', 'email' => 'ot@ertis.test', 'password' => self::PASSWORD, 'role' => User::SAFETY, 'position' => 'Специалист по охране труда']);
            User::create(['organization_id' => $org->id, 'name' => 'Литвинов Игорь Владимирович', 'email' => 'master@ertis.test', 'password' => self::PASSWORD, 'role' => User::FOREMAN, 'position' => 'Мастер электромонтажного участка']);

            $customers = $this->customers();
            foreach (['ПНХЗ' => 'pnhz', 'ГРЭС-1' => 'gres1', 'КЭЗ' => 'kez'] as $short => $login) {
                $c = $customers[$short];
                User::create([
                    'customer_id' => $c->id,
                    'name' => ['ПНХЗ' => 'Омаров Ерболат Нурланович', 'ГРЭС-1' => 'Григорьев Сергей Петрович', 'КЭЗ' => 'Байжанова Динара Асылбековна'][$short],
                    'email' => 'ot.'.$login.'@zakazchik.test',
                    'password' => self::PASSWORD,
                    'role' => User::CUSTOMER,
                    'position' => 'Инженер по допуску подрядных организаций',
                ]);
            }

            $profiles = [];
            foreach (Catalog::profileTemplates() as $tpl) {
                $p = RequirementProfile::create([
                    'organization_id' => $org->id,
                    'customer_id' => $customers[$tpl['customer']]->id,
                    'name' => $tpl['name'],
                    'work_type' => $tpl['work_type'],
                    'description' => $tpl['description'],
                ]);
                foreach ($tpl['items'] as $item) {
                    [$code, $group] = is_array($item) ? $item : [$item, null];
                    $p->items()->create(['document_type_id' => $types[$code]->id, 'min_group' => $group]);
                }
                $profiles[$tpl['name']] = $p;
            }

            $workers = [];
            foreach ($this->people() as $i => $person) {
                $workers[$i] = $this->worker($org, $types, $person, $i);
            }

            $facilities = Facility::all()->keyBy('name');
            $signer = new ListSigner;

            $r1 = $this->request($org, $head, 1, $customers['ГРЭС-1'], $facilities['Энергоблок №5, котлотурбинный цех'], $profiles['ГРЭС: работы в электроустановках'],
                'Замена кабельных линий 6 кВ и наладка релейной защиты', -18, 40, [0, 1, 2, 3, 4, 5]);
            $this->sign($signer, $r1, $head, -21);
            $r1->update(['status' => AdmissionRequest::SUBMITTED, 'submitted_at' => $this->today->subDays(21)->setTime(11, 5)]);
            $rep = User::where('email', 'ot.gres1@zakazchik.test')->first();
            $r1->update(['status' => AdmissionRequest::APPROVED, 'reviewed_at' => $this->today->subDays(19)->setTime(15, 40), 'reviewed_by' => $rep->id, 'review_comment' => 'Пакет проверен, работники допущены. Пропуска оформлены в бюро пропусков.']);
            DB::table('admission_request_workers')->where('admission_request_id', $r1->id)->update(['customer_decision' => 'ok']);

            $r2 = $this->request($org, $head, 2, $customers['ПНХЗ'], $facilities['Установка ЛК-6У, секция 300'], $profiles['ПНХЗ: огневые работы'],
                'Замена участка трубопровода обвязки печи, сварочные работы', 4, 30, [8, 9, 10, 20]);
            $this->sign($signer, $r2, $head, 0);
            $r2->update(['status' => AdmissionRequest::SUBMITTED, 'submitted_at' => $this->today->setTime(9, 20)]);

            $this->request($org, $head, 3, $customers['КЭЗ'], $facilities['Корпус электролиза №2'], $profiles['ERG: работы на высоте'],
                'Монтаж площадок обслуживания и замена ограждений кровли', 7, 60, [14, 15, 16, 17, 18, 19, 21]);
        });
    }

    /** @return array<string, Customer> */
    private function customers(): array
    {
        $rows = [
            ['ПНХЗ', 'ТОО «Павлодарский нефтехимический завод»', 'Павлодар', 'Нефтепереработка, группа КМГ', [['Установка ЛК-6У, секция 300', 'г. Павлодар, промзона ПНХЗ', 'Действующая установка. Огневые и газоопасные работы только по наряду-допуску заказчика.']]],
            ['ГРЭС-1', 'ТОО «Экибастузская ГРЭС-1 имени Булата Нуржанова»', 'Экибастуз', 'Энергетика', [['Энергоблок №5, котлотурбинный цех', 'Павлодарская обл., г. Экибастуз', 'Работы в действующих электроустановках, требуется группа ЭБ не ниже IV для старших.']]],
            ['ГРЭС-2', 'АО «Станция Экибастузская ГРЭС-2»', 'Экибастуз', 'Энергетика', []],
            ['КЭЗ', 'АО «Казахстанский электролизный завод»', 'Павлодар', 'Металлургия, группа ERG', [['Корпус электролиза №2', 'г. Павлодар, промзона КЭЗ', 'Работы на высоте на кровле и площадках, сильное магнитное поле.']]],
            ['АлК', 'АО «Алюминий Казахстана»', 'Павлодар', 'Металлургия, группа ERG', []],
        ];
        $out = [];
        foreach ($rows as [$short, $name, $city, $industry, $facilities]) {
            $c = Customer::create(['short_name' => $short, 'name' => $name, 'city' => $city, 'industry' => $industry]);
            foreach ($facilities as [$fname, $addr, $notes]) {
                $c->facilities()->create(['name' => $fname, 'address' => $addr, 'notes' => $notes]);
            }
            $out[$short] = $c;
        }

        return $out;
    }

    /**
     * Each person: [last, first, middle, male, birth, position, grade, site, role, overrides]
     *
     * @return array<int, array<int, mixed>>
     */
    private function people(): array
    {
        $el = 'Электромонтажный участок';
        $mk = 'Участок металлоконструкций';
        $rm = 'Ремонтно-механический участок';

        return [
            ['Ахметов', 'Ерлан', 'Серикович', true, '1986-03-14', 'Электромонтёр по ремонту и обслуживанию электрооборудования', 6, $el, 'electric', ['eb' => [140, 5]]],
            ['Ковалёв', 'Сергей', 'Викторович', true, '1979-11-02', 'Электромонтёр по ремонту и обслуживанию электрооборудования', 5, $el, 'electric', ['eb' => [200, 4], 'med' => 26]],
            ['Жумабеков', 'Данияр', 'Ермекович', true, '1991-06-21', 'Электромонтажник', 5, $el, 'electric', ['eb' => [95, 4], 'first_aid' => 12]],
            ['Петренко', 'Андрей', 'Николаевич', true, '1983-01-30', 'Электромонтажник', 4, $el, 'electric', ['eb' => [260, 4]]],
            ['Нургалиев', 'Асхат', 'Кайратович', true, '1994-09-08', 'Электромонтёр по ремонту и обслуживанию электрооборудования', 4, $el, 'electric', ['eb' => [180, 4], 'repeat' => 6]],
            ['Литвинов', 'Игорь', 'Владимирович', true, '1975-04-17', 'Мастер участка', null, $el, 'master', ['eb' => [300, 5]]],
            ['Искаков', 'Бауыржан', 'Талгатович', true, '1997-12-03', 'Электромонтажник', 3, $el, 'electric', ['eb' => [120, 2]]],
            ['Шевченко', 'Виктор', 'Иванович', true, '1968-07-25', 'Электромонтёр по ремонту и обслуживанию электрооборудования', 6, $el, 'electric', ['eb' => [-9, 5]]],
            ['Тулеуов', 'Ержан', 'Абаевич', true, '1988-02-11', 'Электрогазосварщик', 6, $rm, 'welder', []],
            ['Кузнецов', 'Дмитрий', 'Александрович', true, '1990-05-19', 'Электрогазосварщик', 5, $rm, 'welder', ['fire' => 21]],
            ['Бекмуханов', 'Галымжан', 'Муратович', true, '1985-10-28', 'Электрогазосварщик', 5, $rm, 'welder', ['welder' => 75]],
            ['Морозов', 'Алексей', 'Сергеевич', true, '1993-08-14', 'Слесарь-ремонтник', 5, $rm, 'fitter', ['gas' => 9]],
            ['Сейткали', 'Арман', 'Нурланович', true, '1999-03-05', 'Слесарь-ремонтник', 4, $rm, 'fitter', ['med' => -4]],
            ['Белоусов', 'Евгений', 'Павлович', true, '1981-12-22', 'Монтажник технологических трубопроводов', 5, $rm, 'fitter', ['renewed' => 'pb']],
            ['Абдрахманов', 'Мейрам', 'Жанатович', true, '1987-06-09', 'Монтажник стальных и железобетонных конструкций', 5, $mk, 'mounter', ['eb' => [210, 2]]],
            ['Зайцев', 'Максим', 'Олегович', true, '1995-01-16', 'Монтажник стальных и железобетонных конструкций', 4, $mk, 'mounter', ['eb' => [150, 2], 'height' => 13]],
            ['Оспанов', 'Темирлан', 'Русланович', true, '2000-10-01', 'Монтажник стальных и железобетонных конструкций', 3, $mk, 'mounter', ['eb' => [90, 2], 'missing' => ['psych']]],
            ['Ткаченко', 'Владимир', 'Анатольевич', true, '1972-05-27', 'Монтажник стальных и железобетонных конструкций', 6, $mk, 'mounter', ['eb' => [240, 3], 'height' => -15]],
            ['Мусин', 'Канат', 'Серикбаевич', true, '1989-09-30', 'Стропальщик', 4, $mk, 'slinger', ['missing' => [], 'extra' => ['psych' => 500, 'eb' => [130, 2]]]],
            ['Захаров', 'Роман', 'Юрьевич', true, '1992-04-04', 'Монтажник стальных и железобетонных конструкций', 4, $mk, 'mounter', ['eb' => [170, 2], 'ppe' => 5]],
            ['Есенов', 'Руслан', 'Амангельдиевич', true, '1984-11-12', 'Газорезчик', 5, $mk, 'welder', ['extra' => ['height' => 220, 'psych' => 900, 'eb' => [160, 2]]]],
            ['Сидоренко', 'Павел', 'Геннадьевич', true, '1977-02-19', 'Прораб', null, $mk, 'master', ['eb' => [280, 4]]],
            ['Хасенов', 'Дамир', 'Ринатович', true, '1996-07-07', 'Изолировщик на термоизоляции', 4, $rm, 'insulator', []],
            ['Ким', 'Ольга', 'Викторовна', false, '1990-03-23', 'Изолировщик на термоизоляции', 4, $rm, 'insulator', ['ot' => 18]],
            ['Гордиенко', 'Николай', 'Петрович', true, '1970-08-31', 'Слесарь-ремонтник', 6, $rm, 'fitter', ['psych' => -40]],
            ['Сагындыков', 'Айдос', 'Маратович', true, '1998-12-18', 'Стропальщик', 3, $mk, 'slinger', ['sling' => 3]],
            ['Касенова', 'Жанар', 'Ермековна', false, '1994-05-02', 'Инженер-наладчик', null, $el, 'electric', ['eb' => [190, 4]]],
            ['Нуржанов', 'Ерасыл', 'Болатович', true, '2002-02-27', 'Электромонтажник', 2, $el, 'electric', ['eb' => [60, 3], 'missing' => ['height', 'first_aid']]],
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<string, DocumentType>  $types
     * @param  array<int, mixed>  $p
     */
    private function worker(Organization $org, $types, array $p, int $index): Worker
    {
        [$last, $first, $middle, $male, $birth, $position, $grade, $site, $role, $over] = $p;
        $iin = null;
        $serial = 101 + $index * 37;
        while ($iin === null) {
            $iin = Iin::make(CarbonImmutable::parse($birth), $male, $serial++);
        }
        $hired = $this->today->subDays(mt_rand(200, 2600));
        $worker = Worker::create([
            'organization_id' => $org->id,
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle,
            'iin' => $iin,
            'birth_date' => Iin::birthDate($iin)->toDateString(),
            'position' => $position,
            'grade' => $grade,
            'site' => $site,
            'phone' => sprintf('+7 70%d %03d %02d %02d', mt_rand(0, 8), mt_rand(100, 999), mt_rand(10, 99), mt_rand(10, 99)),
            'hired_at' => $hired->toDateString(),
            'is_active' => true,
        ]);
        if ($index % 5 !== 3) {
            $photo = SampleScans::avatar('photos/'.$org->id, mb_substr($first, 0, 1).mb_substr($last, 0, 1), $index);
            if ($photo) {
                $worker->update(['photo_path' => $photo]);
            }
        }

        $codes = array_merge(['med', 'intro', 'repeat', 'ot', 'ppe'], self::ROLE_DOCS[$role]);
        $missing = $over['missing'] ?? [];
        $plan = [];
        foreach ($codes as $code) {
            if (in_array($code, $missing, true)) {
                continue;
            }
            $plan[$code] = $over[$code] ?? null;
        }
        foreach ($over['extra'] ?? [] as $code => $spec) {
            $plan[$code] = $spec;
        }

        foreach ($plan as $code => $spec) {
            $type = $types[$code];
            $group = null;
            $offset = null;
            if (is_array($spec)) {
                [$offset, $group] = $spec;
            } elseif (is_int($spec)) {
                $offset = $spec;
            }
            if ($type->has_group && ! $group) {
                $group = 3;
            }
            if ($code === 'intro') {
                $this->document($worker, $type, null, $hired, $group);

                continue;
            }
            $offset ??= mt_rand(45, max(60, min(330, ($type->validity_months ?? 12) * 30 - 20)));
            $until = $this->today->addDays($offset);
            if (($over['renewed'] ?? null) === $code) {
                $oldUntil = $this->today->subDays(25);
                $this->document($worker, $type, $oldUntil, null, $group, true);
            }
            $this->document($worker, $type, $until, null, $group);
        }

        return $worker;
    }

    private function document(Worker $worker, DocumentType $type, ?CarbonImmutable $until, ?CarbonImmutable $issued, ?int $group, bool $old = false): void
    {
        $this->seq++;
        $issued ??= $until && $type->validity_months ? $until->subMonthsNoOverflow($type->validity_months)->addDay() : $this->today->subDays(300);
        $prefix = ['med' => 'МО', 'psych' => 'ПО', 'intro' => 'ВИ', 'repeat' => 'ПИ', 'ot' => 'ОТ', 'pb' => 'ПБ', 'eb' => 'ЭБ', 'height' => 'ВР', 'fire' => 'ПТМ', 'gas' => 'ГОР', 'confined' => 'ЗП', 'sling' => 'С', 'welder' => 'АС', 'lift' => 'Л', 'first_aid' => 'ПП', 'ppe' => 'СИЗ'][$type->code] ?? 'Д';
        $number = $prefix.'-'.str_pad((string) (140 + $this->seq * 7 % 900), 4, '0', STR_PAD_LEFT);
        $issuer = self::ISSUERS[$type->code] ?? 'Учебный центр, г. Павлодар';
        $scan = null;
        if ($this->seq % 9 !== 4) {
            $fields = [
                'Номер' => $number,
                'Выдано' => $worker->fullName(),
                'ИИН' => $worker->iin,
                'Должность' => $worker->position,
                'Дата выдачи' => $issued->format('d.m.Y'),
                'Действительно до' => $until ? $until->format('d.m.Y') : 'бессрочно',
            ];
            if ($group) {
                $fields['Группа по ЭБ'] = \App\Services\Readiness::roman($group);
            }
            $scan = SampleScans::certificate('scans/'.$worker->organization_id.'/'.$worker->id, $type->name, $fields, $issuer);
        }
        WorkerDocument::create([
            'worker_id' => $worker->id,
            'document_type_id' => $type->id,
            'number' => $number,
            'issued_at' => $issued->toDateString(),
            'valid_until' => $until?->toDateString(),
            'issuer' => $issuer,
            'group_level' => $type->has_group ? $group : null,
            'scan_path' => $scan,
            'scan_name' => $scan ? $type->short_name.'_'.$number.'.jpg' : null,
            'scan_mime' => $scan ? 'image/jpeg' : null,
            'scan_size' => $scan ? \Illuminate\Support\Facades\Storage::disk('local')->size($scan) : null,
            'notes' => $old ? 'Предыдущее удостоверение, заменено новым' : null,
        ]);
    }

    /** @param int[] $workerIdx */
    private function request(Organization $org, User $head, int $n, Customer $customer, Facility $facility, RequirementProfile $profile, string $description, int $startOffset, int $days, array $workerIdx): AdmissionRequest
    {
        $start = $this->today->addDays($startOffset);
        $r = AdmissionRequest::create([
            'organization_id' => $org->id,
            'number' => sprintf('ДП-%d-%03d', $start->year, $n),
            'customer_id' => $customer->id,
            'facility_id' => $facility->id,
            'requirement_profile_id' => $profile->id,
            'work_type' => $profile->work_type,
            'work_description' => $description,
            'start_date' => $start->toDateString(),
            'end_date' => $start->addDays($days)->toDateString(),
            'status' => AdmissionRequest::DRAFT,
            'created_by' => $head->id,
            'created_at' => $this->today->addDays(min(0, $startOffset) - 3)->setTime(10, 0),
        ]);
        $ids = Worker::where('organization_id', $org->id)->orderBy('id')->pluck('id')->all();
        $r->workers()->sync(array_map(fn ($i) => $ids[$i], $workerIdx));

        return $r;
    }

    private function sign(ListSigner $signer, AdmissionRequest $r, User $user, int $dayOffset): void
    {
        $r->refresh();
        $text = $signer->payloadText($r);
        $sig = $signer->emulate(base64_encode($text), $user);
        $res = $signer->verify($sig, $text, $r->organization->bin);
        $r->update([
            'signature' => $sig,
            'signed_hash' => hash('sha256', $text),
            'signed_at' => $this->today->addDays($dayOffset)->setTime(10, 30),
            'signed_by' => $user->id,
            'sign_mode' => $res['mode'],
            'signer_subject' => $res['subject'],
        ]);
    }
}
