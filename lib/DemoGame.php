<?php
declare(strict_types=1);

/**
 * Isolated local preview used only while the production DB settings are absent.
 * State lives in the PHP session and never writes to Cardinal tables.
 */
final class DemoGame
{
    /** @var array<string, mixed> */
    private $state;

    public function __construct()
    {
        if (!isset($_SESSION['cardinal_demo_state']) || !is_array($_SESSION['cardinal_demo_state'])) {
            $_SESSION['cardinal_demo_state'] = $this->defaults();
        }
        $this->state =& $_SESSION['cardinal_demo_state'];
        $this->refreshDerived();
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'player' => [
                'id' => 500001, 'name' => 'قهرمان پیش‌نمایش', 'gender' => 'male', 'classId' => 1, 'className' => 'Warrior',
                'coins' => 1250, 'level' => 3, 'experience' => 420, 'nextLevelExperience' => 0,
                'currentFloor' => 1, 'lastFloorUnlocked' => 1, 'location' => 'city',
                'kills' => 0, 'deaths' => 0, 'monstersKilled' => 0, 'pkStatus' => 'white',
                'bagLevel' => 1, 'bagSlots' => ['used' => 0, 'max' => 10], 'battlePower' => 0,
                'noble' => false, 'nobleExpiryDate' => null, 'referralCode' => 'WEBDEMO5', 'referralCount' => 0, 'accountSaved' => false,
                'cooldowns' => ['hunt' => null, 'dungeon' => null, 'cityEntry' => null, 'boss' => null, 'teamBoss' => null],
            ],
            'party' => null,
            'items' => [
                ['itemId' => 1, 'itemName' => 'شمشیر عیار نوآموز', 'description' => 'سلاح تیز و برنده طبقه اول', 'typeId' => 1, 'typeName' => 'سلاح', 'quantity' => 1, 'priceCoins' => 150, 'equipped' => true],
                ['itemId' => 3, 'itemName' => 'کریستال تلپورت طبقات', 'description' => 'آیتم جادویی برای جابه‌جایی سریع', 'typeId' => 3, 'typeName' => 'مصرفی', 'quantity' => 2, 'priceCoins' => 100, 'equipped' => false],
            ],
            'miniItems' => [['miniItemId' => 1, 'name' => 'سنگِ مهتاب', 'description' => 'ماده اولیه کمیاب', 'quantity' => 3]],
            'craftedItems' => [], 'claimed' => false, 'notifications' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function &player(): array
    {
        return $this->state['player'];
    }

    private function requireCity(): void
    {
        $player =& $this->player();
        if (($player['location'] ?? '') !== 'city') throw new GameException('این بخش فقط در منطقه امن شهر در دسترس است.');
    }

    private function requireWild(): void
    {
        $player =& $this->player();
        if (($player['location'] ?? '') !== 'wild') throw new GameException('برای این عمل ابتدا از شهر خارج شوید.');
    }

    private function refreshDerived(): void
    {
        $player =& $this->player();
        $player['nextLevelExperience'] = CardinalRules::nextLevelXp((int) $player['level']);
        $weapon = false; $armor = false;
        foreach ($this->state['items'] as $item) {
            if (!empty($item['equipped']) && ($item['typeId'] ?? 0) === 1) $weapon = true;
            if (!empty($item['equipped']) && ($item['typeId'] ?? 0) === 2) $armor = true;
        }
        $player['battlePower'] = CardinalRules::levelPower((int) $player['level']) + ($weapon ? 50 : 0) + ($armor ? 30 : 0);
        $used = 0;
        foreach ($this->state['items'] as $item) if (empty($item['equipped'])) $used += (int) $item['quantity'];
        foreach ($this->state['miniItems'] as $item) $used += (int) $item['quantity'];
        $used += count(array_filter($this->state['craftedItems'], function (array $item): bool { return empty($item['equipped']); }));
        $player['bagSlots'] = ['used' => $used, 'max' => CardinalRules::maxSlots((int) $player['bagLevel'], (string) $player['className'], !empty($player['noble']))];
    }

    /** @return array<string, mixed> */
    private function event(string $kind, string $title, string $text, array $extra = []): array
    {
        return array_merge(['kind' => $kind, 'title' => $title, 'text' => $text, 'refresh' => true], $extra);
    }

    /** @return array<string, mixed> */
    public function snapshot(int $ignored): array
    {
        $this->refreshDerived();
        return $this->state['player'];
    }

    /** @return array<string, mixed> */
    public function authenticate(string $phone, string $password): array
    {
        return ['playerId' => 500001, 'player' => $this->snapshot(500001)];
    }

    /** @return array<string, mixed> */
    public function register(string $name, string $gender, $classId, ?string $referralCode = null): array
    {
        $name = trim($name);
        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 15) throw new GameException('نام بازیکن باید بین ۳ تا ۱۵ کاراکتر باشد.');
        $classId = (int) $classId;
        if (!isset(CardinalRules::CLASS_NAMES[$classId])) throw new GameException('کلاس نامعتبر است.');
        $player =& $this->player();
        $player['name'] = $name; $player['gender'] = $gender === 'female' ? 'female' : 'male'; $player['classId'] = $classId; $player['className'] = CardinalRules::CLASS_NAMES[$classId];
        $player['referralCode'] = 'WEB' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 5));
        $player['coins'] = $referralCode !== null && trim($referralCode) !== '' ? 300 : 100;
        return ['playerId' => 500001, 'player' => $this->snapshot(500001)];
    }

    /** @return array<string, mixed> */
    public function saveAccount(int $ignored, string $phone, string $password): array
    {
        $this->requireCity();
        if (preg_match('/^\d{11}$/', $phone) !== 1 || strlen($password) < 8) throw new GameException('شماره ۱۱ رقمی و رمز معتبر وارد کنید.');
        $player =& $this->player(); $player['accountSaved'] = true;
        return $this->event('success', 'پیشرفت ذخیره شد', 'این عملیات در پیش‌نمایش فقط شبیه‌سازی شده است.');
    }
    /** @return array<string, mixed> */
    public function changePhone(int $ignored, string $currentPassword, string $phone): array { $this->requireCity(); return $this->event('success', 'شماره تغییر کرد', 'این عملیات در پیش‌نمایش شبیه‌سازی شده است.'); }
    /** @return array<string, mixed> */
    public function changePassword(int $ignored, string $currentPassword, string $nextPassword): array { $this->requireCity(); return $this->event('success', 'رمز تغییر کرد', 'این عملیات در پیش‌نمایش شبیه‌سازی شده است.'); }
    /** @return array<string, mixed> */
    public function changeName(int $ignored, string $name): array
    {
        $this->requireCity();
        $name = trim($name); if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 15) throw new GameException('نام باید بین ۳ تا ۱۵ کاراکتر باشد.');
        $player =& $this->player(); $player['name'] = $name;
        return $this->event('success', 'نام تغییر کرد', 'از این پس با نام «' . $name . '» شناخته می‌شوید.');
    }
    /** @return array<string, mixed> */
    public function deleteAccount(int $ignored, string $confirmation): array { $this->requireCity(); return $this->event('danger', 'پیش‌نمایش', 'حذف حساب در حالت پیش‌نمایش انجام نمی‌شود.'); }

    /** @return array<string, mixed> */
    public function getEquipment(int $ignored): array
    {
        $weapon = null; $armor = null; $pet = null;
        foreach ($this->state['items'] as $item) {
            if (empty($item['equipped'])) continue;
            if ($item['typeId'] === 1) $weapon = $item;
            elseif ($item['typeId'] === 2) $armor = $item;
            elseif ($item['typeId'] === 4) $pet = $item;
        }
        return [
            'weapon' => $weapon ? ['itemId' => $weapon['itemId'], 'instanceId' => null, 'name' => $weapon['itemName'], 'power' => 50] : ['itemId' => null, 'instanceId' => null, 'name' => 'شمشیر چوبی بی‌ارزش (پیش‌فرض)', 'power' => 0],
            'armor' => $armor ? ['itemId' => $armor['itemId'], 'instanceId' => null, 'name' => $armor['itemName'], 'power' => 30] : ['itemId' => null, 'instanceId' => null, 'name' => 'لباس پارچه‌ای بی‌ارزش (پیش‌فرض)', 'power' => 0],
            'pet' => $pet ? ['itemId' => $pet['itemId'], 'name' => $pet['itemName']] : ['itemId' => null, 'name' => 'بدون حیوان همراه'],
        ];
    }

    /** @return array<string, mixed> */
    public function getInventory(int $ignored): array { $this->requireCity(); return ['player' => $this->snapshot(500001), 'items' => $this->state['items'], 'miniItems' => $this->state['miniItems'], 'craftedItems' => $this->state['craftedItems']]; }
    /** @return array<string, mixed> */
    public function getShop(int $ignored): array
    {
        $this->requireCity();
        $player =& $this->player();
        return ['coins' => $player['coins'], 'floor' => $player['currentFloor'], 'items' => [
            ['item_id' => 1, 'item_name' => 'شمشیر عیار نوآموز', 'type_id' => 1, 'description' => 'سلاح تیز و برنده طبقه اول', 'price_coins' => 150],
            ['item_id' => 2, 'item_name' => 'زره چرمی کهنه‌سرباز', 'type_id' => 2, 'description' => 'پوشش مقاوم در برابر ضربات ماب‌ها', 'price_coins' => 200],
            ['item_id' => 3, 'item_name' => 'کریستال تلپورت طبقات', 'type_id' => 3, 'description' => 'آیتم جادویی برای جابه‌جایی سریع', 'price_coins' => 100],
            ['item_id' => 5, 'item_name' => 'تخم اژدهای کوچک', 'type_id' => 4, 'description' => 'حیوان همراه افسانه‌ای', 'price_coins' => 500],
        ], 'crafted' => [['craft_item_id' => 1, 'item_type' => 'weapon', 'item_name' => 'تیغه تمرینی', 'floor' => $player['currentFloor'], 'base_power' => 80, 'base_price' => 500, 'price_type' => 'coins']]];
    }

    /** @return array<int, array<string, mixed>> */
    public function notifications(int $ignored): array { $messages = $this->state['notifications']; $this->state['notifications'] = []; return $messages; }
    /** @return array<string, mixed> */
    public function getBoss(int $ignored): array
    {
        $this->requireWild();
        $player =& $this->player();
        return ['floor' => $player['currentFloor'], 'name' => 'نگهبان سنگی طبقه', 'description' => 'غولی عظیم با ضربه‌های سنگین.', 'yourPower' => $player['battlePower'], 'canSpy' => $player['classId'] === 4];
    }
    /** @return array<string, mixed> */
    public function getDailyQuests(int $ignored): array { $this->requireCity(); return ['available' => [['id' => 10, 'type' => 'monster', 'targetName' => 'گرگ سایه‌رو', 'floor' => 1, 'targetCount' => 3, 'progress' => 0, 'rewardXp' => 225, 'rewardCoins' => 90, 'deadline' => null, 'status' => 'available'], ['id' => 11, 'type' => 'boss', 'targetName' => 'نگهبان سنگی طبقه', 'floor' => 1, 'targetCount' => 1, 'progress' => 0, 'rewardXp' => 250, 'rewardCoins' => 200, 'deadline' => null, 'status' => 'available']], 'active' => [], 'completed' => [], 'activatedToday' => 0]; }
    /** @return array<string, mixed> */
    public function getParty(int $ignored): array
    {
        $party = $this->state['party'] ?? null;
        if (!is_array($party)) return ['party' => null, 'members' => []];
        $player =& $this->player();
        return ['party' => $party, 'members' => [[
            'id' => $player['id'], 'name' => $player['name'], 'level' => $player['level'],
            'className' => $player['className'], 'pkStatus' => $player['pkStatus'], 'leader' => true,
        ]]];
    }
    /** @return array<string, mixed> */
    public function getSocial(int $ignored): array { $this->requireCity(); return ['partner' => null, 'guild' => null, 'party' => $this->getParty(500001)['party']]; }
    /** @return null */
    public function getGuild(int $ignored) { $this->requireCity(); return null; }
    /** @return array<string, mixed> */
    public function leaderboard(string $kind, int $page = 1): array
    {
        $player =& $this->player();
        $titles = ['players' => 'برترین بازیکنان (بر اساس سطح)', 'killers' => 'برترین قاتلان', 'guilds' => 'برترین گیلدها', 'groups' => 'برترین گروه‌ها'];
        $rows = ['players' => [['rank' => 1, 'name' => 'آلفا', 'level' => 42, 'experience' => 54300], ['rank' => 2, 'name' => 'سایه‌نقره‌ای', 'level' => 35, 'experience' => 22200], ['rank' => 3, 'name' => $player['name'], 'level' => $player['level'], 'experience' => $player['experience']]], 'killers' => [['rank' => 1, 'name' => 'شکارچی‌سرخ', 'kills' => 84, 'pkStatus' => 'black'], ['rank' => 2, 'name' => 'گرگ تنها', 'kills' => 31, 'pkStatus' => 'red']], 'guilds' => [['rank' => 1, 'name' => 'پیمان سپیده‌دم', 'leader' => 'فرمانده سپیده', 'members' => 18, 'totalLevel' => 238]], 'groups' => [['rank' => 1, 'name' => 'گروه کاردینال', 'members' => 103, 'totalLevel' => 996]]];
        return ['title' => $titles[$kind] ?? 'برترین‌ها', 'page' => $page, 'totalPages' => 1, 'rows' => $rows[$kind] ?? []];
    }
    /** @return array<string, mixed> */
    public function getCrafting(int $ignored): array
    {
        $this->requireCity();
        $player =& $this->player(); if ($player['classId'] !== 3) throw new GameException('فقط کلاس آهنگر به کارگاه دسترسی دارد.', 403);
        return ['collected' => [], 'plans' => [['id' => 1, 'name' => 'تیغه تمرینی', 'type' => 'weapon', 'floor' => 1, 'power' => 80, 'minutes' => 20, 'materials' => ['1' => 2]]], 'queues' => [], 'crafted' => []];
    }
    /** @return array<int, array<string, mixed>> */
    public function getAchievements(int $ignored): array
    {
        return [['number' => 1, 'name' => 'نشان پیش‌نمایش کاردینال', 'description' => 'نمونه نمایشی دستاورد فصلی؛ در نسخه عملیاتی، کارت‌های واقعی همین حساب نمایش داده می‌شوند.']];
    }

    /** @return array<string, mixed> */
    public function getNoble(int $ignored): array
    {
        $this->requireCity();
        $player =& $this->player(); return ['active' => $player['noble'], 'expiry' => $player['nobleExpiryDate'], 'owned' => [], 'offer' => ['id' => 1, 'number' => 1, 'name' => 'نشان اشراف فصل اول', 'description' => 'دسترسی به سیاه‌چال و امتیازهای ویژه.', 'price' => 75000, 'validUntil' => null]];
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function action(int $ignored, string $action, array $payload = []): array
    {
        $player =& $this->player();
        $cityActions = ['teleport', 'dungeon', 'buy-item', 'buy-crafted', 'sell-item', 'transfer-coins', 'transfer-item', 'transfer-mini', 'transfer-crafted', 'drop-item', 'drop-mini', 'accept-daily', 'invite-partner', 'accept-partner', 'remove-partner', 'create-guild', 'join-guild', 'guild-request', 'guild-kick', 'guild-rank', 'guild-slogan', 'guild-transfer', 'guild-disband', 'guild-leave', 'craft', 'upgrade', 'collect-crafting', 'create-noble-invoice', 'create-bag-invoice', 'create-crafted-invoice'];
        $wildActions = ['hunt', 'boss-fight', 'spy-boss', 'pvp', 'create-team-pvp', 'create-team-boss', 'join-party'];
        if (in_array($action, $cityActions, true)) $this->requireCity();
        if (in_array($action, $wildActions, true)) $this->requireWild();
        if ($action === 'exit-city' && $player['location'] !== 'city') throw new GameException('شما هم‌اکنون بیرون از شهر هستید.');
        if (($action === 'return-city' || $action === 'force-return-city') && $player['location'] !== 'wild') throw new GameException('شما هم‌اکنون در شهر هستید.');
        if ($action === 'claim-daily') { if (!empty($this->state['claimed'])) throw new GameException('سهمیه جوایز روزانه امروز قبلاً دریافت شده است.'); $this->state['claimed'] = true; $player['coins'] += 1000; $player['experience'] += 500; return $this->event('success', 'هدیه روزانه کاردینال', 'سهمیه امروز با موفقیت به حساب شما واریز شد.', ['rewards' => ['coins' => 1000, 'xp' => 500, 'items' => []]]); }
        if ($action === 'exit-city') { $player['location'] = 'wild'; return $this->event('warning', 'خروج از شهر', 'شما وارد منطقه ناامن شدید.'); }
        if ($action === 'return-city' || $action === 'force-return-city') { $player['location'] = 'city'; return $this->event('success', 'ورود به منطقه امن', 'به شهر طبقه ' . $player['currentFloor'] . ' بازگشتید.'); }
        if ($action === 'teleport') { $target = (int) ($payload['floor'] ?? 0); if ($target < 1 || $target > $player['lastFloorUnlocked']) throw new GameException('قفل این طبقه هنوز باز نشده است.'); $player['currentFloor'] = $target; return $this->event('success', 'انتقال موفق', 'شما به شهر طبقه ' . $target . ' منتقل شدید.'); }
        if ($action === 'hunt') { $player['coins'] += 80; $player['experience'] += 120; $player['monstersKilled']++; return $this->event('success', 'شکار موفق', 'گرگ سایه‌رو شکست خورد.', ['rewards' => ['coins' => 80, 'xp' => 120, 'items' => [], 'miniItems' => []]]); }
        if ($action === 'boss-fight') { $player['coins'] += 500; $player['experience'] += 350; return $this->event('success', 'نبرد مجدد موفق', 'نگهبان سنگی شکست خورد.', ['rewards' => ['coins' => 500, 'xp' => 350, 'items' => [], 'miniItems' => []]]); }
        if ($action === 'dungeon') { if (empty($player['noble'])) throw new GameException('سیاه‌چال مخفی تنها برای دارندگان نشان اشراف فعال است.', 403); $player['coins'] += 500; return $this->event('success', 'کاوش موفق', 'در اعماق دانجن پیروز شدید.', ['rewards' => ['coins' => 500, 'xp' => 1000, 'items' => [], 'miniItems' => []]]); }
        if ($action === 'spy-boss') { if ($player['classId'] !== 4) throw new GameException('فقط بازیکنان کلاس قاتل مهارت جاسوسی از رئیس طبقه را دارند.', 403); return $this->event('info', 'گزارش جاسوسی رئیس طبقه', 'اسکن مخفی نگهبان سنگی با موفقیت انجام شد.', ['bossSpyReport' => ['floor' => $player['currentFloor'], 'name' => 'نگهبان سنگی طبقه', 'description' => 'غولی عظیم با ضربه‌های سنگین.', 'level' => 400, 'battlePower' => 4000], 'refresh' => false]); }
        if ($action === 'spy-player') { if ($player['classId'] !== 4) throw new GameException('فقط بازیکنان کلاس قاتل مهارت جاسوسی دارند.', 403); return $this->event('info', 'گزارش جاسوسی موفق', 'رد یک آواتار هم‌سطح در شبکه کاردینال پیدا شد.', ['spyReport' => ['id' => (int) ($payload['targetId'] ?? 100), 'name' => 'سایه‌ی پیش‌نمایش', 'level' => $player['level'], 'coins' => 870, 'floor' => 1, 'pkStatus' => 'green']]); }
        if (in_array($action, ['create-party', 'create-team-pvp', 'create-team-boss'], true)) {
            if (is_array($this->state['party'] ?? null)) throw new GameException('شما هم‌اکنون عضو یک تیم هستید.');
            // Like the shared private bot menu, a defensive lobby may be
            // prepared in town; its members still have to join from the wild.
            if ($action !== 'create-party' && $player['location'] !== 'wild') throw new GameException('برای این فراخوان ابتدا از شهر خارج شوید.');
            $target = $action === 'create-team-pvp' ? ['id' => (int) ($payload['targetId'] ?? 100), 'name' => 'هدف پیش‌نمایش', 'level' => 3, 'floor' => $player['currentFloor'], 'location' => 'wild'] : null;
            $this->state['party'] = ['lobbyCode' => (string) random_int(100000, 999999), 'lobbyType' => $action === 'create-party' ? 'defensive' : 'offensive', 'floor' => $player['currentFloor'], 'leaderId' => $player['id'], 'targetPlayerId' => $target['id'] ?? null, 'target' => $target, 'members' => [$player['id']], 'capacity' => $action === 'create-party' ? 7 : 5, 'teamPower' => $player['battlePower'], 'hasWarLord' => $player['className'] === 'War Lord'];
            return $this->event('success', $action === 'create-team-pvp' ? 'لشکرکشی تشکیل شد' : ($action === 'create-team-boss' ? 'فراخوان فتح طبقه ثبت شد' : 'تیم دفاعی تشکیل شد'), 'این تیم در حالت پیش‌نمایش فقط برای نمایش رابط ساخته شد.');
        }
        if ($action === 'start-party-battle') { if (!is_array($this->state['party'] ?? null)) throw new GameException('شما عضو هیچ تیمی نیستید.'); $team = $this->state['party']; $this->state['party'] = null; return $this->event('success', !empty($team['targetPlayerId']) ? 'حمله تیمی موفق' : 'فتح گروهی طبقه', 'نتیجه نبرد تیمی در پیش‌نمایش شبیه‌سازی شد.', ['rewards' => ['coins' => 200, 'items' => []]]); }
        if ($action === 'leave-party' || $action === 'disband-party') { if (!is_array($this->state['party'] ?? null)) throw new GameException('شما عضو هیچ تیمی نیستید.'); $this->state['party'] = null; return $this->event('warning', 'تیم منحل شد', 'تیم نمایشی منحل شد.'); }
        if ($action === 'create-noble-invoice' || $action === 'create-bag-invoice' || $action === 'create-crafted-invoice') return $this->event('info', 'فاکتور پرداخت ثبت شد', 'این فاکتور در حالت پیش‌نمایش ثبت واقعی نمی‌شود.', ['refresh' => false]);
        if ($action === 'accept-daily') return $this->event('success', 'مأموریت پذیرفته شد', 'مهلت تکمیل این مأموریت ۲۴ ساعت است.');
        if ($action === 'equip' || $action === 'unequip' || $action === 'summon-pet' || $action === 'rest-pet') return $this->event('success', 'تجهیزات تغییر کرد', 'این تغییر در پیش‌نمایش شبیه‌سازی شد.');
        if ($action === 'craft' || $action === 'upgrade' || $action === 'collect-crafting') return $this->event('info', 'کارگاه', 'کارگاه در پیش‌نمایش به‌صورت نمایشی فعال است.', ['refresh' => false]);
        if (in_array($action, ['pvp', 'buy-item', 'buy-crafted', 'sell-item', 'transfer-coins', 'transfer-item', 'transfer-mini', 'transfer-crafted', 'drop-item', 'drop-mini', 'join-party', 'invite-partner', 'accept-partner', 'remove-partner', 'create-guild', 'join-guild', 'guild-request', 'guild-kick', 'guild-rank', 'guild-slogan', 'guild-transfer', 'guild-disband', 'guild-leave'], true)) return $this->event('info', 'پیش‌نمایش', 'این فرمان در حالت پیش‌نمایش فقط شبیه‌سازی می‌شود.', ['refresh' => false]);
        throw new GameException('فرمان بازی شناخته نشد.');
    }
}
