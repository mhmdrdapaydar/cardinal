<?php
declare(strict_types=1);

require_once __DIR__ . '/Rules.php';

final class GameException extends RuntimeException
{
    public function __construct(string $message, int $status = 400)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        $status = $this->getCode();
        return $status >= 400 && $status < 600 ? $status : 400;
    }
}

/**
 * PHP implementation of the existing Cardinal gameplay adapter. It uses only
 * existing tables and intentionally contains no CREATE, ALTER, or DROP query.
 */
final class CardinalGame
{
    private PDO $pdo;
    private const WEB_SERVER_ID = 5;
    private const WEB_PREFIX = 'web:';

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /** @return mixed */
    private function transaction(callable $callback)
    {
        $started = !$this->pdo->inTransaction();
        if ($started) $this->pdo->beginTransaction();
        try {
            $result = $callback();
            if ($started) $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($started && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $error;
        }
    }

    private function statement(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($params) as $index => $value) {
            $parameter = $index + 1;
            if ($value === null) {
                $statement->bindValue($parameter, null, PDO::PARAM_NULL);
            } elseif (is_bool($value)) {
                $statement->bindValue($parameter, $value ? 1 : 0, PDO::PARAM_INT);
            } elseif (is_int($value)) {
                $statement->bindValue($parameter, $value, PDO::PARAM_INT);
            } else {
                $statement->bindValue($parameter, (string) $value, PDO::PARAM_STR);
            }
        }
        $statement->execute();
        return $statement;
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(string $sql, array $params = []): array
    {
        return $this->statement($sql, $params)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    private function one(string $sql, array $params = []): ?array
    {
        $row = $this->statement($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array{affectedRows:int, insertId:int} */
    private function execute(string $sql, array $params = []): array
    {
        $statement = $this->statement($sql, $params);
        return ['affectedRows' => $statement->rowCount(), 'insertId' => (int) $this->pdo->lastInsertId()];
    }

    private static function num($value, $fallback = 0): float
    {
        return is_numeric($value) ? (float) $value : (float) $fallback;
    }

    private static function integer($value, int $fallback = 0): int
    {
        return (int) self::num($value, $fallback);
    }

    private static function text($value, string $fallback = ''): string
    {
        return $value === null ? $fallback : (string) $value;
    }

    private static function boolValue($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private static function utc(): DateTimeZone
    {
        static $utc = null;
        if ($utc === null) $utc = new DateTimeZone('UTC');
        return $utc;
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::utc());
    }

    /** @return DateTimeImmutable|null */
    private static function dateValue($value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') return null;
        try {
            if ($value instanceof DateTimeInterface) {
                return (new DateTimeImmutable('@' . $value->getTimestamp()))->setTimezone(self::utc());
            }
            return new DateTimeImmutable((string) $value, self::utc());
        } catch (Throwable $error) {
            return null;
        }
    }

    private static function mysqlDate(DateTimeInterface $date): string
    {
        return $date->setTimezone(self::utc())->format('Y-m-d H:i:s');
    }

    /** @return string|null */
    private static function iso($value): ?string
    {
        $date = self::dateValue($value);
        return $date ? $date->setTimezone(self::utc())->format('Y-m-d\TH:i:s\Z') : null;
    }

    private static function sameDay($value): bool
    {
        $date = self::dateValue($value);
        return $date !== null && $date->setTimezone(self::utc())->format('Y-m-d') === self::now()->format('Y-m-d');
    }

    private static function cleanName(string $raw): string
    {
        $name = trim($raw);
        $name = preg_replace('/[\x{200C}\x{200B}]/u', '', $name) ?? '';
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        return trim($name);
    }

    private static function cleanMonsterName(string $raw): string
    {
        return trim(preg_replace('/\s*\d+\s*$/u', '', $raw) ?? '');
    }

    /** @return array<string, float|int> */
    private static function jsonRecord($value): array
    {
        if (is_array($value)) $decoded = $value;
        elseif ($value === null || $value === '') $decoded = [];
        else {
            $decoded = json_decode((string) $value, true);
            if (!is_array($decoded)) $decoded = [];
        }
        $result = [];
        foreach ($decoded as $key => $quantity) {
            if (is_numeric($quantity)) $result[(string) $key] = self::num($quantity);
        }
        return $result;
    }

    private static function validPassword(string $password): bool
    {
        return strlen($password) >= 8
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/\d/', $password) === 1
            && preg_match('/[@!?%=$#&*+\-^_]/', $password) === 1
            && preg_match('/^[\x21-\x7e]+$/', $password) === 1;
    }

    private static function passwordHash(string $password): string
    {
        // Matches the legacy bot SHA-256 format so existing accounts keep working.
        return hash('sha256', $password);
    }

    /** @return array<string, mixed> */
    private static function event(string $kind, string $title, string $text, array $extra = []): array
    {
        return array_merge(['kind' => $kind, 'title' => $title, 'text' => $text, 'refresh' => true], $extra);
    }

    private static function assertInt($value, string $label, int $min = 1, int $max = PHP_INT_MAX): int
    {
        if (!is_numeric($value)) throw new GameException($label . ' نامعتبر است.');
        $number = (float) $value;
        if (floor($number) !== $number || $number < $min || $number > $max) {
            throw new GameException($label . ' نامعتبر است.');
        }
        return (int) $number;
    }

    /** @return array<string, mixed> */
    private function readPlayer(int $playerId, bool $lock = false): array
    {
        $row = $this->one(
            'SELECT p.*, c.class_name, c.tax_rate, c.inventory_bonus_slots
             FROM players p
             LEFT JOIN classes c ON p.class_id = c.class_id
             WHERE p.player_id = ? AND p.is_deleted = FALSE ' . ($lock ? 'FOR UPDATE' : ''),
            [$playerId]
        );
        if (!$row) throw new GameException('آواتار پیدا نشد یا حذف شده است.', 404);
        return $row;
    }

    /** @return array<string, mixed>|null */
    private function readPlayerByPhone(string $phone): ?array
    {
        return $this->one(
            'SELECT p.*, c.class_name, c.tax_rate, c.inventory_bonus_slots
             FROM players p
             LEFT JOIN classes c ON p.class_id = c.class_id
             WHERE p.phone_number = ? AND p.is_deleted = FALSE',
            [$phone]
        );
    }

    /** @param array<string, mixed> $player */
    private function isNoble(array $player): bool
    {
        $expiry = self::dateValue($player['noble_expiry_date'] ?? null);
        $today = self::now()->setTime(0, 0, 0);
        return self::boolValue($player['noble_status'] ?? false) && ($expiry === null || $expiry >= $today);
    }

    /** @param array<string, mixed> $player */
    private function requireCity(array $player): void
    {
        if (($player['current_location'] ?? 'city') !== 'city') {
            throw new GameException('این امکان فقط داخل شهر (منطقه امن) در دسترس است. ابتدا به شهر بازگردید.');
        }
    }

    /** @param array<string, mixed> $player */
    private function requireWild(array $player): void
    {
        if (($player['current_location'] ?? 'city') !== 'wild') {
            throw new GameException('این امکان فقط بیرون از شهر در دسترس است. ابتدا از شهر خارج شوید.');
        }
    }

    /** @param array<string, mixed> $player
     * @return array<string, mixed> */
    private function expireNoble(array $player): array
    {
        $expiry = self::dateValue($player['noble_expiry_date'] ?? null);
        if (self::boolValue($player['noble_status'] ?? false) && $expiry && $expiry < self::now()->setTime(0, 0, 0)) {
            $this->execute('UPDATE players SET noble_status = FALSE WHERE player_id = ?', [self::integer($player['player_id'])]);
            $player['noble_status'] = false;
        }
        return $player;
    }

    private function expireRegularQuests(int $playerId): void
    {
        $overdue = $this->rows(
            "SELECT pq.quest_id, q.penalty_coins
             FROM player_quests pq JOIN quests q ON q.quest_id = pq.quest_id
             WHERE pq.player_id = ? AND pq.status = 'active' AND pq.deadline < NOW()",
            [$playerId]
        );
        foreach ($overdue as $quest) {
            $this->execute("UPDATE player_quests SET status = 'failed' WHERE player_id = ? AND quest_id = ?", [$playerId, self::integer($quest['quest_id'] ?? 0)]);
            $this->execute('UPDATE players SET coins = coins - ? WHERE player_id = ?', [self::num($quest['penalty_coins'] ?? 0), $playerId]);
        }
    }

    /** @param array<string, mixed> $player
     * @return array<string, mixed> */
    private function levelUpOnce(array $player): array
    {
        $needed = CardinalRules::nextLevelXp(self::integer($player['level'] ?? 1, 1));
        if (self::num($player['experience'] ?? 0) >= $needed) {
            $this->execute('UPDATE players SET level = level + 1, experience = experience - ? WHERE player_id = ?', [$needed, self::integer($player['player_id'])]);
            $player['level'] = self::integer($player['level'] ?? 1) + 1;
            $player['experience'] = self::num($player['experience'] ?? 0) - $needed;
        }
        return $player;
    }

    /** @return array<string, mixed> */
    private function preparePlayer(int $playerId, bool $lock = false): array
    {
        $player = $this->readPlayer($playerId, $lock);
        $player = $this->expireNoble($player);
        $this->expireRegularQuests(self::integer($player['player_id']));
        return $this->levelUpOnce($player);
    }

    private function usedInventorySlots(int $playerId): int
    {
        $normal = $this->one(
            'SELECT COALESCE(SUM(inv.quantity), 0) AS total
             FROM inventory inv
             LEFT JOIN equipment e ON inv.player_id = e.player_id
               AND (inv.item_id = e.weapon_id OR inv.item_id = e.armor_id OR inv.item_id = e.pet_id)
             WHERE inv.player_id = ? AND e.player_id IS NULL',
            [$playerId]
        );
        $mini = $this->one('SELECT COALESCE(SUM(quantity), 0) AS total FROM mini_item_inventory WHERE player_id = ?', [$playerId]);
        $crafted = $this->one(
            'SELECT COUNT(*) AS total
             FROM player_crafted_items pci
             LEFT JOIN equipment e ON pci.owner_player_id = e.player_id
               AND (pci.instance_id = e.crafted_weapon_instance_id OR pci.instance_id = e.crafted_armor_instance_id)
             WHERE pci.owner_player_id = ? AND e.player_id IS NULL',
            [$playerId]
        );
        return self::integer($normal['total'] ?? 0) + self::integer($mini['total'] ?? 0) + self::integer($crafted['total'] ?? 0);
    }

    /** @param array<string, mixed> $player */
    private function hasInventorySpace(array $player, int $additional = 1): bool
    {
        $max = CardinalRules::maxSlots(self::integer($player['bag_level'] ?? 0), isset($player['class_name']) ? self::text($player['class_name']) : null, $this->isNoble($player));
        return $this->usedInventorySlots(self::integer($player['player_id'])) + $additional <= $max;
    }

    private function battlePower(int $playerId, bool $pvp = false): int
    {
        $player = $this->readPlayer($playerId);
        $power = CardinalRules::levelPower(self::integer($player['level'] ?? 1, 1));
        $equipment = $this->one('SELECT weapon_id, armor_id, crafted_weapon_instance_id, crafted_armor_instance_id FROM equipment WHERE player_id = ?', [$playerId]);
        if ($equipment) {
            if (!empty($equipment['crafted_weapon_instance_id'])) {
                $crafted = $this->one('SELECT current_power FROM player_crafted_items WHERE instance_id = ?', [self::integer($equipment['crafted_weapon_instance_id'])]);
                $power += self::integer($crafted['current_power'] ?? 0);
            } elseif (!empty($equipment['weapon_id'])) {
                $power += 50;
            }
            if (!empty($equipment['crafted_armor_instance_id'])) {
                $crafted = $this->one('SELECT current_power FROM player_crafted_items WHERE instance_id = ?', [self::integer($equipment['crafted_armor_instance_id'])]);
                $power += self::integer($crafted['current_power'] ?? 0);
            } elseif (!empty($equipment['armor_id'])) {
                $power += 30;
            }
        }
        if ($pvp && self::integer($player['class_id']) === 4) $power = (int) floor($power * 1.3);
        return $power;
    }

    /** @param array<string, mixed> $player
     * @return array<string, mixed> */
    private function publicPlayer(array $player): array
    {
        $noble = $this->isNoble($player);
        $playerId = self::integer($player['player_id']);
        return [
            'id' => $playerId,
            'name' => self::text($player['player_name'] ?? ''),
            'gender' => ($player['gender'] ?? 'male') === 'female' ? 'female' : 'male',
            'classId' => self::integer($player['class_id']),
            'className' => self::text($player['class_name'] ?? (CardinalRules::CLASS_NAMES[self::integer($player['class_id'])] ?? 'Warrior')),
            'coins' => self::integer($player['coins'] ?? 0),
            'level' => self::integer($player['level'] ?? 1, 1),
            'experience' => self::integer($player['experience'] ?? 0),
            'nextLevelExperience' => CardinalRules::nextLevelXp(self::integer($player['level'] ?? 1, 1)),
            'currentFloor' => self::integer($player['current_floor'] ?? 1, 1),
            'lastFloorUnlocked' => self::integer($player['last_floor_unlocked'] ?? 1, 1),
            'location' => ($player['current_location'] ?? 'city') === 'wild' ? 'wild' : 'city',
            'kills' => self::integer($player['kills'] ?? 0),
            'deaths' => self::integer($player['deaths'] ?? 0),
            'monstersKilled' => self::integer($player['monsters_killed'] ?? 0),
            'pkStatus' => self::text($player['pk_status'] ?? 'white'),
            'bagLevel' => self::integer($player['bag_level'] ?? 1, 1),
            'bagSlots' => ['used' => $this->usedInventorySlots($playerId), 'max' => CardinalRules::maxSlots(self::integer($player['bag_level'] ?? 1), isset($player['class_name']) ? self::text($player['class_name']) : null, $noble)],
            'battlePower' => $this->battlePower($playerId),
            'noble' => $noble,
            'nobleExpiryDate' => self::iso($player['noble_expiry_date'] ?? null),
            'referralCode' => $player['referral_code'] ?? null,
            'referralCount' => self::integer(($this->one('SELECT COUNT(*) AS total FROM referrals WHERE referrer_id = ? AND reward_given_to_referrer = TRUE', [$playerId]) ?? [])['total'] ?? 0),
            'accountSaved' => !empty($player['phone_number']) && !empty($player['password_hash']),
            'cooldowns' => [
                'hunt' => self::iso($player['hunt_lock_until'] ?? null),
                'dungeon' => self::iso($player['dungeon_cooldown_until'] ?? null),
                'cityEntry' => self::iso($player['city_entry_cooldown_until'] ?? null),
                'boss' => self::iso($player['lock_until'] ?? null),
                // Team-boss fatigue is a native bot field. Expose it for the
                // web HUD; it does not introduce any new persistence.
                'teamBoss' => self::iso($player['team_boss_cooldown_until'] ?? null),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            return $this->publicPlayer($this->preparePlayer($playerId, true));
        });
    }

    /** @return array{playerId:int, player:array<string,mixed>} */
    public function authenticate(string $phone, string $password): array
    {
        $phone = trim($phone);
        return $this->transaction(function () use ($phone, $password): array {
            $player = $this->readPlayerByPhone($phone);
            if (!$player) throw new GameException('حسابی با این شماره در کاردینال پیدا نشد.', 401);
            $lock = self::dateValue($player['lock_until'] ?? null);
            if (self::integer($player['failed_login_attempts'] ?? 0) >= 5 && $lock && $lock > self::now()) {
                throw new GameException('به‌دلیل ۵ تلاش ناموفق، این حساب موقتاً مسدود است.', 423);
            }
            if (empty($player['password_hash']) || !hash_equals((string) $player['password_hash'], self::passwordHash($password))) {
                $attempts = self::integer($player['failed_login_attempts'] ?? 0) + 1;
                $lockUntil = $attempts >= 5 ? self::mysqlDate(self::now()->modify('+12 hours')) : null;
                $this->execute('UPDATE players SET failed_login_attempts = ?, lock_until = ? WHERE player_id = ?', [$attempts, $lockUntil, self::integer($player['player_id'])]);
                $message = $attempts >= 5 ? 'به‌دلیل ۵ تلاش ناموفق، حساب برای ۱۲ ساعت مسدود شد.' : 'رمز عبور اشتباه است؛ ' . (5 - $attempts) . ' فرصت باقی مانده.';
                throw new GameException($message, 401);
            }
            $playerId = self::integer($player['player_id']);
            $this->execute('UPDATE players SET server_id = ?, failed_login_attempts = 0, lock_until = NULL WHERE player_id = ?', [self::WEB_SERVER_ID, $playerId]);
            $fresh = $this->preparePlayer($playerId, true);
            return ['playerId' => $playerId, 'player' => $this->publicPlayer($fresh)];
        });
    }

    /** @return array{playerId:int, player:array<string,mixed>} */
    public function register(string $name, string $gender, $classId, ?string $referralCode = null): array
    {
        $name = self::cleanName($name);
        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 15) throw new GameException('نام بازیکن باید بین ۳ تا ۱۵ کاراکتر باشد.');
        $classId = self::assertInt($classId, 'کلاس', 1, 5);
        if ($gender !== 'male' && $gender !== 'female') throw new GameException('جنسیت انتخاب‌شده نامعتبر است.');
        return $this->transaction(function () use ($name, $gender, $classId, $referralCode): array {
            $referrerId = null;
            if ($referralCode !== null && trim($referralCode) !== '') {
                $referrer = $this->one('SELECT player_id FROM players WHERE referral_code = ?', [trim($referralCode)]);
                if (!$referrer) throw new GameException('کد معرف در سیستم کاردینال یافت نشد.');
                $referrerId = self::integer($referrer['player_id']);
            }
            $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $referral = '';
            for ($i = 0; $i < 8; $i++) $referral .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            $uuid = bin2hex(random_bytes(16));
            $webIdentity = self::WEB_PREFIX . substr($uuid, 0, 8) . '-' . substr($uuid, 8, 4) . '-' . substr($uuid, 12, 4) . '-' . substr($uuid, 16, 4) . '-' . substr($uuid, 20);
            $result = $this->execute(
                'INSERT INTO players (server_id, messenger_user_id, chat_id_pv, player_name, gender, class_id, referral_code, referrer_id, coins) VALUES (?, ?, NULL, ?, ?, ?, ?, ?, 100)',
                [self::WEB_SERVER_ID, $webIdentity, $name, $gender, $classId, $referral, $referrerId]
            );
            $playerId = $result['insertId'];
            if (!$playerId) throw new GameException('ایجاد آواتار ناموفق بود.', 500);
            if ($referrerId !== null) {
                $this->execute('UPDATE players SET coins = coins + 500 WHERE player_id = ?', [$referrerId]);
                $this->execute('UPDATE players SET coins = coins + 200 WHERE player_id = ?', [$playerId]);
                $this->execute('INSERT INTO referrals (referrer_id, referred_player_id, reward_given_to_referrer, reward_given_to_referred) VALUES (?, ?, TRUE, TRUE)', [$referrerId, $playerId]);
            }
            return ['playerId' => $playerId, 'player' => $this->publicPlayer($this->preparePlayer($playerId, true))];
        });
    }

    /** @return array<string, mixed> */
    public function saveAccount(int $playerId, string $phone, string $password): array
    {
        $phone = trim($phone);
        if (preg_match('/^\d{11}$/', $phone) !== 1) throw new GameException('شماره تلفن باید ۱۱ رقم انگلیسی باشد.');
        if (!self::validPassword($password)) throw new GameException('رمز عبور باید حداقل ۸ کاراکتر و شامل حرف بزرگ، حرف کوچک، عدد و نماد مجاز باشد.');
        return $this->transaction(function () use ($playerId, $phone, $password): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if ($this->one('SELECT player_id FROM players WHERE phone_number = ? AND player_id != ?', [$phone, $playerId])) throw new GameException('این شماره تلفن پیش‌تر به حساب دیگری متصل شده است.');
            $this->execute('UPDATE players SET phone_number = ?, password_hash = ?, failed_login_attempts = 0, lock_until = NULL WHERE player_id = ?', [$phone, self::passwordHash($password), $playerId]);
            return self::event('success', 'پیشرفت ذخیره شد', 'نام کاربری و رمز عبور حساب شما در پایگاه داده مرکزی ثبت شد.');
        });
    }

    /** @return array<string, mixed> */
    public function changePhone(int $playerId, string $currentPassword, string $phone): array
    {
        $phone = trim($phone);
        if (preg_match('/^\d{11}$/', $phone) !== 1) throw new GameException('شماره تلفن باید ۱۱ رقم انگلیسی باشد.');
        return $this->transaction(function () use ($playerId, $currentPassword, $phone): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if (empty($player['password_hash']) || !hash_equals((string) $player['password_hash'], self::passwordHash($currentPassword))) throw new GameException('رمز عبور فعلی صحیح نیست.', 401);
            if ($this->one('SELECT player_id FROM players WHERE phone_number = ? AND player_id != ?', [$phone, $playerId])) throw new GameException('این شماره تلفن قبلاً استفاده شده است.');
            $this->execute('UPDATE players SET phone_number = ? WHERE player_id = ?', [$phone, $playerId]);
            return self::event('success', 'شماره تلفن تغییر کرد', 'نام کاربری جدید شما با موفقیت ذخیره شد.');
        });
    }

    /** @return array<string, mixed> */
    public function changePassword(int $playerId, string $currentPassword, string $nextPassword): array
    {
        if (!self::validPassword($nextPassword)) throw new GameException('رمز جدید باید شرایط رمز عبور کاردینال را داشته باشد.');
        return $this->transaction(function () use ($playerId, $currentPassword, $nextPassword): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if (empty($player['password_hash']) || !hash_equals((string) $player['password_hash'], self::passwordHash($currentPassword))) throw new GameException('رمز عبور فعلی صحیح نیست.', 401);
            $this->execute('UPDATE players SET password_hash = ? WHERE player_id = ?', [self::passwordHash($nextPassword), $playerId]);
            return self::event('success', 'رمز عبور تغییر کرد', 'رمز عبور جدید حساب شما ذخیره شد.');
        });
    }

    /** @return array<string, mixed> */
    public function changeName(int $playerId, string $name): array
    {
        $name = self::cleanName($name);
        if (mb_strlen($name, 'UTF-8') < 3 || mb_strlen($name, 'UTF-8') > 15) throw new GameException('نام بازیکن باید بین ۳ تا ۱۵ کاراکتر باشد.');
        return $this->transaction(function () use ($playerId, $name): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $this->execute('UPDATE players SET player_name = ? WHERE player_id = ?', [$name, $playerId]);
            return self::event('success', 'نام آواتار تغییر کرد', 'از این پس در جهان کاردینال با نام «' . $name . '» شناخته می‌شوید.');
        });
    }

    /** @return array<string, mixed> */
    public function deleteAccount(int $playerId, string $confirmation): array
    {
        if ($confirmation !== 'تایید حذف') throw new GameException('برای حذف حساب باید دقیقاً عبارت «تایید حذف» را وارد کنید.');
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if ($this->one('SELECT guild_id FROM guilds WHERE leader_id = ?', [$playerId])) throw new GameException('رهبر گیلد ابتدا باید مالکیت را واگذار یا گیلد را منحل کند.');
            $identifier = 'deleted_' . $playerId . '_' . time();
            $this->execute('UPDATE players SET is_deleted = TRUE, messenger_user_id = ?, chat_id_pv = NULL WHERE player_id = ?', [$identifier, $playerId]);
            return self::event('danger', 'آواتار حذف شد', 'حساب و پیشرفت آواتار شما برای همیشه حذف شد.', ['refresh' => false]);
        });
    }

    /** @return array<string, mixed> */
    public function getEquipment(int $playerId): array
    {
        $player = $this->readPlayer($playerId);
        $equipment = $this->one('SELECT * FROM equipment WHERE player_id = ?', [self::integer($player['player_id'])]);
        $result = [
            'weapon' => ['itemId' => null, 'instanceId' => null, 'name' => 'شمشیر چوبی بی‌ارزش (پیش‌فرض)', 'power' => 0],
            'armor' => ['itemId' => null, 'instanceId' => null, 'name' => 'لباس پارچه‌ای بی‌ارزش (پیش‌فرض)', 'power' => 0],
            'pet' => ['itemId' => null, 'name' => 'بدون حیوان همراه'],
        ];
        if (!$equipment) return $result;
        if (!empty($equipment['crafted_weapon_instance_id'])) {
            $crafted = $this->one('SELECT pci.current_power, pci.upgrade_level, ci.item_name FROM player_crafted_items pci JOIN craftable_items ci ON ci.craft_item_id = pci.craft_item_id WHERE pci.instance_id = ?', [self::integer($equipment['crafted_weapon_instance_id'])]);
            if ($crafted) $result['weapon'] = ['itemId' => null, 'instanceId' => self::integer($equipment['crafted_weapon_instance_id']), 'name' => self::text($crafted['item_name']) . ' ⚒️ (لول ' . self::integer($crafted['upgrade_level']) . ' | قدرت ' . self::integer($crafted['current_power']) . ')', 'power' => self::integer($crafted['current_power'])];
        } elseif (!empty($equipment['weapon_id'])) {
            $item = $this->one('SELECT item_name FROM items WHERE item_id = ?', [self::integer($equipment['weapon_id'])]);
            $result['weapon'] = ['itemId' => self::integer($equipment['weapon_id']), 'instanceId' => null, 'name' => self::text($item['item_name'] ?? null, 'نامشخص'), 'power' => 50];
        }
        if (!empty($equipment['crafted_armor_instance_id'])) {
            $crafted = $this->one('SELECT pci.current_power, pci.upgrade_level, ci.item_name FROM player_crafted_items pci JOIN craftable_items ci ON ci.craft_item_id = pci.craft_item_id WHERE pci.instance_id = ?', [self::integer($equipment['crafted_armor_instance_id'])]);
            if ($crafted) $result['armor'] = ['itemId' => null, 'instanceId' => self::integer($equipment['crafted_armor_instance_id']), 'name' => self::text($crafted['item_name']) . ' ⚒️ (لول ' . self::integer($crafted['upgrade_level']) . ' | قدرت ' . self::integer($crafted['current_power']) . ')', 'power' => self::integer($crafted['current_power'])];
        } elseif (!empty($equipment['armor_id'])) {
            $item = $this->one('SELECT item_name FROM items WHERE item_id = ?', [self::integer($equipment['armor_id'])]);
            $result['armor'] = ['itemId' => self::integer($equipment['armor_id']), 'instanceId' => null, 'name' => self::text($item['item_name'] ?? null, 'نامشخص'), 'power' => 30];
        }
        if (!empty($equipment['pet_id'])) {
            $item = $this->one('SELECT item_name FROM items WHERE item_id = ?', [self::integer($equipment['pet_id'])]);
            $result['pet'] = ['itemId' => self::integer($equipment['pet_id']), 'name' => self::text($item['item_name'] ?? null, 'بدون حیوان همراه')];
        }
        return $result;
    }

    /** @return array<string, mixed> */
    public function getInventory(int $playerId): array
    {
        // Reading the backpack is deliberately allowed outside the city.
        // equip(), unequip() and getEquipment() have never had a location
        // guard, so gating only this read made the equipment panel fail in the
        // wild: it loads equipment and inventory together and rendered its
        // error state when either call was refused. This is a read-only query
        // and changes no rule, cost, reward or progression value.
        $player = $this->readPlayer($playerId);
        $items = $this->rows(
            "SELECT inv.quantity, i.item_id, i.item_name, i.description, i.type_id, i.price_coins,
                    COALESCE(t.type_name, '') AS type_name,
                    CASE WHEN e.player_id IS NULL THEN FALSE ELSE TRUE END AS equipped
             FROM inventory inv JOIN items i ON i.item_id = inv.item_id
             LEFT JOIN item_types t ON t.type_id = i.type_id
             LEFT JOIN equipment e ON e.player_id = inv.player_id
                AND (inv.item_id = e.weapon_id OR inv.item_id = e.armor_id OR inv.item_id = e.pet_id)
             WHERE inv.player_id = ? AND inv.item_id != 4 ORDER BY i.type_id, i.item_name",
            [$playerId]
        );
        $miniItems = $this->rows('SELECT mii.mini_item_id, mii.quantity, mi.name, mi.description FROM mini_item_inventory mii JOIN mini_items mi ON mi.mini_item_id = mii.mini_item_id WHERE mii.player_id = ? AND mii.quantity > 0 ORDER BY mi.name', [$playerId]);
        $crafted = $this->rows(
            "SELECT pci.instance_id, pci.craft_item_id, pci.current_power, pci.upgrade_level, ci.item_type, ci.item_name, ci.is_tradeable, ci.is_upgradeable,
                    CASE WHEN e.player_id IS NULL THEN FALSE ELSE TRUE END AS equipped
             FROM player_crafted_items pci JOIN craftable_items ci ON ci.craft_item_id = pci.craft_item_id
             LEFT JOIN equipment e ON e.player_id = pci.owner_player_id
                AND (pci.instance_id = e.crafted_weapon_instance_id OR pci.instance_id = e.crafted_armor_instance_id)
             WHERE pci.owner_player_id = ? ORDER BY pci.created_at DESC",
            [$playerId]
        );
        return [
            'player' => $this->publicPlayer($player),
            'items' => array_map(function (array $row): array { return ['itemId' => self::integer($row['item_id']), 'itemName' => self::text($row['item_name']), 'description' => $row['description'] === null ? null : self::text($row['description']), 'typeId' => self::integer($row['type_id']), 'typeName' => self::text($row['type_name']), 'quantity' => self::integer($row['quantity']), 'priceCoins' => $row['price_coins'] === null ? null : self::integer($row['price_coins']), 'equipped' => self::boolValue($row['equipped'])]; }, $items),
            'miniItems' => array_map(function (array $row): array { return ['miniItemId' => self::integer($row['mini_item_id']), 'name' => self::text($row['name']), 'description' => $row['description'] === null ? null : self::text($row['description']), 'quantity' => self::integer($row['quantity'])]; }, $miniItems),
            'craftedItems' => array_map(function (array $row): array { return ['instanceId' => self::integer($row['instance_id']), 'craftItemId' => self::integer($row['craft_item_id']), 'itemType' => self::text($row['item_type']), 'itemName' => self::text($row['item_name']), 'currentPower' => self::integer($row['current_power']), 'upgradeLevel' => self::integer($row['upgrade_level']), 'isTradeable' => self::boolValue($row['is_tradeable']), 'isUpgradeable' => self::boolValue($row['is_upgradeable']), 'equipped' => self::boolValue($row['equipped'])]; }, $crafted),
        ];
    }

    /** @return array<string, mixed> */
    public function getShop(int $playerId): array
    {
        $player = $this->readPlayer($playerId);
        $this->requireCity($player);
        return [
            'coins' => self::integer($player['coins']),
            'floor' => self::integer($player['current_floor']),
            'items' => $this->rows('SELECT item_id, item_name, type_id, description, price_coins FROM items WHERE is_available = TRUE AND price_coins IS NOT NULL AND item_id != 4 ORDER BY type_id, item_name'),
            'crafted' => $this->rows('SELECT craft_item_id, item_type, item_name, floor, base_power, base_price, price_type FROM craftable_items WHERE floor = ? ORDER BY item_type, item_name', [self::integer($player['current_floor'])]),
        ];
    }

    /** @param array<string, mixed> $target */
    private function queueNotification(array $target, string $message): void
    {
        $this->execute('INSERT INTO cross_bot_messages (target_player_id, target_server_id, message_text, image_id) VALUES (?, ?, ?, NULL)', [self::integer($target['player_id']), self::integer($target['server_id']), $message]);
    }

    /** @param array<int, string> $lootedItems */
    private function notifyPartnerOfAttack(int $victimId, string $attackerName, int $attackerId, int $floor, array $lootedItems): void
    {
        $couple = $this->one('SELECT player_id_1, player_id_2 FROM couples WHERE player_id_1 = ? OR player_id_2 = ?', [$victimId, $victimId]);
        if (!$couple) return;
        $partnerId = self::integer($couple['player_id_1']) === $victimId ? self::integer($couple['player_id_2']) : self::integer($couple['player_id_1']);
        $partner = $this->one('SELECT player_id, player_name, chat_id_pv, server_id FROM players WHERE player_id = ? AND is_deleted = FALSE', [$partnerId]);
        if (!$partner) return;
        // Native bots only deliver a partner report after the player has
        // opened a private chat. Web accounts use server 5 and receive the
        // same report through the existing cross-server notification queue.
        if (empty($partner['chat_id_pv']) && self::integer($partner['server_id']) !== self::WEB_SERVER_ID) return;
        $victim = $this->one('SELECT player_name FROM players WHERE player_id = ?', [$victimId]);
        $lootSummary = $lootedItems ? implode('، ', $lootedItems) : 'هیچ آیتمی غارت نشد';
        $this->queueNotification(
            $partner,
            '📊 گزارش فوق‌محرمانه: پارتنر شما «' . self::text($victim['player_name'] ?? null, 'نامشخص') . '» در طبقه ' . $floor .
            ' توسط «' . $attackerName . '» (C_ID: ' . $attackerId . ') شکست خورد. غنایم: ' . $lootSummary
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function notifications(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $messages = $this->rows('SELECT queue_id, message_text, created_at FROM cross_bot_messages WHERE target_player_id = ? AND target_server_id = ? AND delivered = FALSE ORDER BY queue_id LIMIT 20', [$playerId, self::WEB_SERVER_ID]);
            if ($messages) {
                $ids = array_map(function (array $row): int { return self::integer($row['queue_id']); }, $messages);
                $marks = implode(',', array_fill(0, count($ids), '?'));
                $this->execute('UPDATE cross_bot_messages SET delivered = TRUE WHERE queue_id IN (' . $marks . ')', $ids);
            }
            return array_map(function (array $row): array { return ['id' => self::integer($row['queue_id']), 'text' => self::text($row['message_text']), 'createdAt' => self::iso($row['created_at'] ?? null)]; }, $messages);
        });
    }

    private function addInventory(int $playerId, int $itemId, int $quantity = 1): void
    {
        $this->execute('INSERT INTO inventory (player_id, item_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?', [$playerId, $itemId, $quantity, $quantity]);
    }

    private function addMiniItem(int $playerId, int $miniItemId, int $quantity = 1): void
    {
        $this->execute('INSERT INTO mini_item_inventory (player_id, mini_item_id, quantity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity = quantity + ?', [$playerId, $miniItemId, $quantity, $quantity]);
    }

    private function deductInventory(int $playerId, int $itemId, int $quantity): void
    {
        $result = $this->execute('UPDATE inventory SET quantity = quantity - ? WHERE player_id = ? AND item_id = ? AND quantity >= ?', [$quantity, $playerId, $itemId, $quantity]);
        if ($result['affectedRows'] !== 1) throw new GameException('تعداد کافی از این آیتم در موجودی شما نیست.');
        $this->execute('DELETE FROM inventory WHERE player_id = ? AND item_id = ? AND quantity <= 0', [$playerId, $itemId]);
    }

    private function deductMini(int $playerId, int $miniItemId, int $quantity): void
    {
        $result = $this->execute('UPDATE mini_item_inventory SET quantity = quantity - ? WHERE player_id = ? AND mini_item_id = ? AND quantity >= ?', [$quantity, $playerId, $miniItemId, $quantity]);
        if ($result['affectedRows'] !== 1) throw new GameException('تعداد کافی از این ماده اولیه در موجودی شما نیست.');
        $this->execute('DELETE FROM mini_item_inventory WHERE player_id = ? AND mini_item_id = ? AND quantity <= 0', [$playerId, $miniItemId]);
    }

    private static function random(): float
    {
        // Math.random() in the source is always below 1; preserve that bound
        // so random array selection cannot reach count($items).
        return random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }

    /** @param array<string, mixed> $player
     * @return array<int, string> */
    private function rollMiniDrops(string $source, int $sourceId, array $player): array
    {
        $links = $source === 'monster'
            ? $this->rows('SELECT mmi.mini_item_id, mmi.drop_rate_override, mi.name, mi.drop_rate FROM monster_mini_items mmi JOIN mini_items mi ON mi.mini_item_id = mmi.mini_item_id WHERE mmi.monster_id = ?', [$sourceId])
            : $this->rows('SELECT bmi.mini_item_id, bmi.drop_rate_override, mi.name, mi.drop_rate FROM boss_mini_items bmi JOIN mini_items mi ON mi.mini_item_id = bmi.mini_item_id WHERE bmi.boss_id = ?', [$sourceId]);
        $drops = [];
        foreach ($links as $link) {
            $rate = $link['drop_rate_override'] === null ? self::num($link['drop_rate'] ?? 0) : self::num($link['drop_rate_override']);
            if (self::random() * 100 < $rate && $this->hasInventorySpace($player)) {
                $this->addMiniItem(self::integer($player['player_id']), self::integer($link['mini_item_id']));
                $drops[] = self::text($link['name']);
            }
        }
        return $drops;
    }

    /** @param array<string, mixed> $player
     * @return array<int, string> */
    private function rollRegularDrops(array $player, bool $boss = false): array
    {
        $drops = [];
        $standardRate = $boss ? 0.4 : 0.3;
        $petRate = $boss ? 0.15 : 0.1;
        if (self::random() < $standardRate && $this->hasInventorySpace($player)) {
            $available = $this->rows('SELECT item_id, item_name FROM items WHERE type_id IN (1, 2, 3) AND is_available = TRUE AND item_id != 4');
            if ($available) {
                $selected = $available[(int) floor(self::random() * count($available))];
                $this->addInventory(self::integer($player['player_id']), self::integer($selected['item_id']));
                $drops[] = self::text($selected['item_name']);
            }
        }
        if (self::random() < $petRate && $this->hasInventorySpace($player)) {
            $pets = $this->rows('SELECT item_id, item_name FROM items WHERE type_id = 4 AND is_available = TRUE');
            if ($pets) {
                $selected = $pets[(int) floor(self::random() * count($pets))];
                $this->addInventory(self::integer($player['player_id']), self::integer($selected['item_id']));
                $drops[] = '🐾 ' . self::text($selected['item_name']);
            }
        }
        return $drops;
    }

    /** @param array<int, int> $members
     * @return array<int, int> */
    private function eligiblePartyMembers(array $members): array
    {
        $eligible = [];
        foreach ($members as $memberId) {
            try {
                $member = $this->readPlayer(self::integer($memberId));
                if ($this->hasInventorySpace($member)) $eligible[] = self::integer($memberId);
            } catch (GameException $error) {
                // The reference bot simply ignores a vanished avatar while
                // distributing a party drop.
            }
        }
        return $eligible;
    }

    /** @param array<int, int> $members
     * @return array<int, array<int, string>> */
    private function rollTeamMiniDrops(string $source, int $sourceId, array $members): array
    {
        $links = $source === 'monster'
            ? $this->rows('SELECT mmi.mini_item_id, mmi.drop_rate_override, mi.name, mi.drop_rate FROM monster_mini_items mmi JOIN mini_items mi ON mi.mini_item_id = mmi.mini_item_id WHERE mmi.monster_id = ?', [$sourceId])
            : $this->rows('SELECT bmi.mini_item_id, bmi.drop_rate_override, mi.name, mi.drop_rate FROM boss_mini_items bmi JOIN mini_items mi ON mi.mini_item_id = bmi.mini_item_id WHERE bmi.boss_id = ?', [$sourceId]);
        $teamSize = max(1, count($members));
        $drops = [];
        foreach ($links as $link) {
            $baseRate = $link['drop_rate_override'] === null ? self::num($link['drop_rate'] ?? 0) : self::num($link['drop_rate_override']);
            // Native party rule: a material is rolled once for the whole
            // party, and its probability is divided by the party size.
            if (self::random() * 100 >= $baseRate / $teamSize) continue;
            $eligible = $this->eligiblePartyMembers($members);
            if (!$eligible) continue;
            $winnerId = $eligible[(int) floor(self::random() * count($eligible))];
            $this->addMiniItem($winnerId, self::integer($link['mini_item_id']));
            if (!isset($drops[$winnerId])) $drops[$winnerId] = [];
            $drops[$winnerId][] = self::text($link['name']);
        }
        return $drops;
    }

    /** @param array<int, int> $members
     * @return array<int, array<int, string>> */
    private function rollTeamBossRegularDrops(array $members): array
    {
        $teamSize = max(1, count($members));
        $drops = [];
        if (self::random() < 0.4 / $teamSize) {
            $eligible = $this->eligiblePartyMembers($members);
            $items = $this->rows('SELECT item_id, item_name FROM items WHERE type_id IN (1, 2, 3) AND is_available = TRUE AND item_id != 4');
            if ($eligible && $items) {
                $winnerId = $eligible[(int) floor(self::random() * count($eligible))];
                $item = $items[(int) floor(self::random() * count($items))];
                $this->addInventory($winnerId, self::integer($item['item_id']));
                $drops[$winnerId] = [self::text($item['item_name'])];
            }
        }
        if (self::random() < 0.15 / $teamSize) {
            $eligible = $this->eligiblePartyMembers($members);
            $pets = $this->rows('SELECT item_id, item_name FROM items WHERE type_id = 4 AND is_available = TRUE');
            if ($eligible && $pets) {
                $winnerId = $eligible[(int) floor(self::random() * count($eligible))];
                $pet = $pets[(int) floor(self::random() * count($pets))];
                $this->addInventory($winnerId, self::integer($pet['item_id']));
                if (!isset($drops[$winnerId])) $drops[$winnerId] = [];
                $drops[$winnerId][] = '🐾 ' . self::text($pet['item_name']);
            }
        }
        return $drops;
    }

    private function advanceRegularQuests(int $playerId, string $action): void
    {
        $quests = $this->rows(
            "SELECT pq.quest_id, pq.progress, q.target_count, q.reward_xp, q.reward_coins, q.reward_items
             FROM player_quests pq JOIN quests q ON q.quest_id = pq.quest_id
             WHERE pq.player_id = ? AND pq.status = 'active' AND pq.deadline > NOW() AND q.target_type = ?",
            [$playerId, $action]
        );
        foreach ($quests as $quest) {
            $progress = self::integer($quest['progress'] ?? 0) + 1;
            if ($progress >= self::integer($quest['target_count'] ?? 0)) {
                $update = $this->execute("UPDATE player_quests SET progress = ?, status = 'completed', completed_at = NOW() WHERE player_id = ? AND quest_id = ? AND status = 'active'", [$progress, $playerId, self::integer($quest['quest_id'])]);
                if ($update['affectedRows']) {
                    $this->execute('UPDATE players SET coins = coins + ?, experience = experience + ? WHERE player_id = ?', [self::num($quest['reward_coins'] ?? 0), self::num($quest['reward_xp'] ?? 0), $playerId]);
                    $rewards = self::jsonRecord($quest['reward_items'] ?? null);
                    // Legacy values are an array of {id, qty}, unlike material maps.
                    $decoded = is_array($quest['reward_items'] ?? null) ? $quest['reward_items'] : json_decode(self::text($quest['reward_items'] ?? '[]', '[]'), true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $reward) {
                            if (is_array($reward) && isset($reward['id'])) $this->addInventory($playerId, self::integer($reward['id']), self::integer($reward['qty'] ?? 1, 1));
                        }
                    }
                }
            } else {
                $this->execute('UPDATE player_quests SET progress = ? WHERE player_id = ? AND quest_id = ?', [$progress, $playerId, self::integer($quest['quest_id'])]);
            }
        }
    }

    private function advanceDailyQuest(int $playerId, string $targetType, string $targetName): void
    {
        $quests = $this->rows('SELECT * FROM player_daily_quests WHERE player_id = ? AND status = \'active\' AND target_type = ?', [$playerId, $targetType]);
        foreach ($quests as $quest) {
            if (self::cleanMonsterName(self::text($quest['target_name'])) !== self::cleanMonsterName($targetName)) continue;
            $progress = self::integer($quest['progress'] ?? 0) + 1;
            if ($progress >= self::integer($quest['target_count'] ?? 0)) {
                $result = $this->execute("UPDATE player_daily_quests SET progress = ?, status = 'completed' WHERE daily_quest_id = ? AND status = 'active'", [$progress, self::integer($quest['daily_quest_id'])]);
                if ($result['affectedRows']) $this->execute('UPDATE players SET coins = coins + ?, experience = experience + ? WHERE player_id = ?', [self::num($quest['reward_coins'] ?? 0), self::num($quest['reward_xp'] ?? 0), $playerId]);
            } else {
                $this->execute('UPDATE player_daily_quests SET progress = ? WHERE daily_quest_id = ?', [$progress, self::integer($quest['daily_quest_id'])]);
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function getLobbies(): array
    {
        $rows = $this->rows('SELECT lobby_code, lobby_data FROM party_lobbies');
        $lobbies = [];
        foreach ($rows as $row) {
            $data = is_array($row['lobby_data'] ?? null) ? $row['lobby_data'] : json_decode(self::text($row['lobby_data'] ?? ''), true);
            if (!is_array($data)) continue;
            $members = [];
            if (isset($data['members']) && is_array($data['members'])) {
                foreach ($data['members'] as $id) {
                    $member = self::integer($id);
                    if ($member) $members[] = $member;
                }
            }
            // The shared bot contract contains only defensive and offensive
            // lobbies. A very old web-only `boss` marker is read as the
            // equivalent offensive lobby, rather than being presented as a
            // third, incompatible type.
            $rawType = self::text($data['type'] ?? '');
            $type = ($rawType === 'offensive' || $rawType === 'boss') ? 'offensive' : 'defensive';
            // The three reference bots persist offensive targets under the
            // key `target`. Earlier web builds used `target_player_id`; read
            // both so no live lobby is lost, but write the bot-compatible key.
            $rawTarget = $data['target'] ?? ($data['target_player_id'] ?? null);
            $targetPlayerId = is_numeric($rawTarget) && self::integer($rawTarget) > 0 ? self::integer($rawTarget) : null;
            $lobbies[] = [
                'lobbyCode' => self::text($row['lobby_code']),
                'lobbyType' => $type,
                'floor' => self::integer($data['floor'] ?? 0),
                'leaderId' => self::integer($data['leader'] ?? 0),
                'targetPlayerId' => $targetPlayerId,
                'members' => $members,
            ];
        }
        return $lobbies;
    }

    /** @param array<string, mixed> $lobby */
    private function setLobby(array $lobby): void
    {
        $data = ['leader' => self::integer($lobby['leaderId']), 'members' => $lobby['members'], 'type' => self::text($lobby['lobbyType']), 'floor' => self::integer($lobby['floor'])];
        // `target` is the exact JSON contract used by Telegram, Rubika, and
        // Bale for an offensive party. This keeps web-created teams usable
        // from every existing bot without a schema change.
        if (($lobby['targetPlayerId'] ?? null) !== null) $data['target'] = self::integer($lobby['targetPlayerId']);
        $this->execute('INSERT INTO party_lobbies (lobby_code, lobby_data) VALUES (?, ?) ON DUPLICATE KEY UPDATE lobby_data = VALUES(lobby_data)', [self::text($lobby['lobbyCode']), json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    private function deleteLobby(string $code): void
    {
        $this->execute('DELETE FROM party_lobbies WHERE lobby_code = ?', [$code]);
    }

    private function removeWrongFloorLobbies(int $playerId, int $floor): void
    {
        foreach ($this->getLobbies() as $lobby) {
            if (!in_array($playerId, $lobby['members'], true) || self::integer($lobby['floor']) === $floor) continue;
            if (self::integer($lobby['leaderId']) === $playerId) {
                $this->deleteLobby(self::text($lobby['lobbyCode']));
            } else {
                $lobby['members'] = array_values(array_filter($lobby['members'], function ($member) use ($playerId): bool { return self::integer($member) !== $playerId; }));
                if ($lobby['members']) $this->setLobby($lobby); else $this->deleteLobby(self::text($lobby['lobbyCode']));
            }
        }
    }


    /** @return array<string, mixed>|null */
    private function lobbyForPlayer(int $playerId): ?array
    {
        foreach ($this->getLobbies() as $lobby) {
            if (in_array($playerId, $lobby['members'], true)) return $lobby;
        }
        return null;
    }

    /** @param array<int, int> $members
     * @return array{power:int,hasWarLord:bool} */
    private function partyMetrics(array $members): array
    {
        $power = 0;
        $hasWarLord = false;
        foreach ($members as $memberId) {
            $memberId = self::integer($memberId);
            $member = $this->one(
                'SELECT p.player_id, c.class_name
                 FROM players p LEFT JOIN classes c ON c.class_id = p.class_id
                 WHERE p.player_id = ? AND p.is_deleted = FALSE',
                [$memberId]
            );
            // calculate_battle_power() in the bot returns 10 for a missing
            // row, rather than crashing the entire party calculation.
            if (!$member) { $power += 10; continue; }
            if (self::text($member['class_name']) === 'War Lord') $hasWarLord = true;
            // A member can be removed between the defensive lookup above and
            // the power lookup. The bots use a base value of 10 for a missing
            // member, so preserve that behaviour instead of failing every
            // remaining member's social/party view.
            try {
                $power += $this->battlePower($memberId);
            } catch (GameException $error) {
                $power += 10;
            }
        }
        if ($hasWarLord) $power = (int) floor($power * 1.25);
        return ['power' => $power, 'hasWarLord' => $hasWarLord];
    }

    /** @param array<int, array<string, mixed>> $lobbies */
    private function nextLobbyCode(array $lobbies): string
    {
        $existing = [];
        foreach ($lobbies as $lobby) $existing[self::text($lobby['lobbyCode'])] = true;
        do { $code = (string) random_int(100000, 999999); } while (isset($existing[$code]));
        return $code;
    }

    private function assertNoActiveParty(int $playerId, array $lobbies): void
    {
        foreach ($lobbies as $lobby) {
            if (in_array($playerId, $lobby['members'], true)) {
                throw new GameException('شما هم‌اکنون عضو یک تیم هستید. ابتدا تیم فعلی را ترک یا منحل کنید.');
            }
        }
    }

    /** @param array<string, mixed> $player */
    private function assertNoTeamBossFatigue(array $player): void
    {
        $until = self::dateValue($player['team_boss_cooldown_until'] ?? null);
        if ($until && $until > self::now()) {
            $seconds = max(0, $until->getTimestamp() - self::now()->getTimestamp());
            $minutes = (int) floor($seconds / 60);
            throw new GameException('خستگی پس از نبرد تیمی فعال است؛ ' . $minutes . ' دقیقه و ' . ($seconds % 60) . ' ثانیه باقی مانده است.');
        }
    }

    /** @return array<string, mixed> */
    public function getBoss(int $playerId): array
    {
        $player = $this->readPlayer($playerId);
        $this->requireWild($player);
        $boss = $this->one('SELECT * FROM bosses WHERE floor = ?', [self::integer($player['current_floor'])]);
        if (!$boss) throw new GameException('باس این طبقه یافت نشد.', 404);
        return [
            'floor' => self::integer($player['current_floor']),
            'name' => self::text($boss['boss_name']),
            'description' => self::text($boss['description'] ?? ''),
            'yourPower' => $this->battlePower($playerId),
            'canSpy' => self::integer($player['class_id']) === 4,
        ];
    }

    /** @return array<string, mixed> */
    public function getDailyQuests(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $this->ensureDailyQuests($player);
            // The native bot closes an unfinished overdue quest when the active
            // quest board is opened, and closes a reward-claimed daily quest
            // after showing its completed line. Mirror that existing lifecycle.
            $this->execute("UPDATE player_daily_quests SET status = 'expired' WHERE player_id = ? AND status = 'active' AND deadline < NOW() AND progress < target_count", [$playerId]);
            $this->execute("UPDATE player_daily_quests SET status = 'completed' WHERE player_id = ? AND status = 'active' AND progress >= target_count", [$playerId]);
            $quests = $this->rows('SELECT * FROM player_daily_quests WHERE player_id = ? ORDER BY daily_quest_id', [$playerId]);
            $meta = $this->one('SELECT * FROM player_quest_meta WHERE player_id = ?', [$playerId]);
            $categories = ['available' => [], 'active' => [], 'completed' => []];
            foreach ($quests as $quest) {
                $data = [
                    'id' => self::integer($quest['daily_quest_id']), 'type' => self::text($quest['target_type']), 'targetName' => self::cleanMonsterName(self::text($quest['target_name'])), 'floor' => self::integer($quest['target_floor']), 'targetCount' => self::integer($quest['target_count']), 'progress' => self::integer($quest['progress']), 'rewardXp' => self::integer($quest['reward_xp']), 'rewardCoins' => self::integer($quest['reward_coins']), 'deadline' => self::iso($quest['deadline'] ?? null), 'status' => self::text($quest['status']),
                ];
                if ($quest['status'] === 'available') $categories['available'][] = $data;
                elseif ($quest['status'] === 'active') $categories['active'][] = $data;
                elseif ($quest['status'] === 'completed') $categories['completed'][] = $data;
            }
            $categories['activatedToday'] = self::sameDay($meta['activated_date'] ?? null) ? self::integer($meta['activated_today'] ?? 0) : 0;
            return $categories;
        });
    }

    /** @param array<string, mixed> $player */
    private function ensureDailyQuests(array $player): void
    {
        $playerId = self::integer($player['player_id']);
        $meta = $this->one('SELECT * FROM player_quest_meta WHERE player_id = ?', [$playerId]);
        $lastGenerated = self::dateValue($meta['last_generated_at'] ?? null);
        if ($lastGenerated && self::now()->getTimestamp() - $lastGenerated->getTimestamp() < 86400) return;
        $this->execute("DELETE FROM player_daily_quests WHERE player_id = ? AND status = 'available'", [$playerId]);
        $unlocked = self::integer($player['last_floor_unlocked'] ?? 1, 1);
        $upperFloor = max(1, $unlocked > 1 ? $unlocked - 1 : 1);
        for ($i = 0; $i < 10; $i++) {
            $floor = 1 + (int) floor(self::random() * $upperFloor);
            if (self::random() < 0.2) {
                $boss = $this->one('SELECT * FROM bosses WHERE floor = ?', [$floor]);
                if ($boss) {
                    $targetCount = 1 + (int) floor(self::random() * 2);
                    $this->execute("INSERT INTO player_daily_quests (player_id, target_type, target_name, target_floor, target_count, reward_xp, reward_coins) VALUES (?, 'boss', ?, ?, ?, ?, ?)", [$playerId, self::text($boss['boss_name']), $floor, $targetCount, (int) floor(self::num($boss['reward_xp'] ?? 0) / 2), self::num($boss['reward_coins'] ?? 0)]);
                    continue;
                }
            }
            $monsters = $this->rows('SELECT * FROM monsters WHERE floor = ?', [$floor]);
            if ($monsters) {
                $monster = $monsters[(int) floor(self::random() * count($monsters))];
                $targetCount = 3 + (int) floor(self::random() * 4);
                $this->execute("INSERT INTO player_daily_quests (player_id, target_type, target_name, target_floor, target_count, reward_xp, reward_coins) VALUES (?, 'monster', ?, ?, ?, ?, ?)", [$playerId, self::text($monster['monster_name']), $floor, $targetCount, self::num($monster['reward_xp'] ?? 0) * $targetCount, self::num($monster['reward_coins'] ?? 0) * $targetCount]);
            }
        }
        $this->execute('INSERT INTO player_quest_meta (player_id, last_generated_at, activated_today, activated_date) VALUES (?, NOW(), 0, CURDATE()) ON DUPLICATE KEY UPDATE last_generated_at = NOW(), activated_today = 0, activated_date = CURDATE()', [$playerId]);
    }

    /** @return array<string, mixed> */
    public function getParty(int $playerId): array
    {
        $party = $this->lobbyForPlayer($playerId);
        if (!$party) return ['party' => null, 'members' => []];

        $members = [];
        foreach ($party['members'] as $id) {
            // A deleted avatar must not make every remaining party member's
            // social panel fail. The native bot similarly skips missing rows.
            $member = $this->one(
                'SELECT p.player_id, p.player_name, p.level, p.pk_status, c.class_name
                 FROM players p LEFT JOIN classes c ON c.class_id = p.class_id
                 WHERE p.player_id = ? AND p.is_deleted = FALSE',
                [self::integer($id)]
            );
            if (!$member) continue;
            $members[] = [
                'id' => self::integer($member['player_id']),
                'name' => self::text($member['player_name']),
                'level' => self::integer($member['level']),
                'className' => self::text($member['class_name']),
                'pkStatus' => self::text($member['pk_status']),
                'leader' => self::integer($member['player_id']) === self::integer($party['leaderId']),
            ];
        }

        $metrics = $this->partyMetrics($party['members']);
        $target = null;
        if (($party['targetPlayerId'] ?? null) !== null) {
            $targetPlayer = $this->one(
                'SELECT player_id, player_name, level, current_floor, current_location
                 FROM players WHERE player_id = ? AND is_deleted = FALSE',
                [self::integer($party['targetPlayerId'])]
            );
            if ($targetPlayer) {
                $target = [
                    'id' => self::integer($targetPlayer['player_id']),
                    'name' => self::text($targetPlayer['player_name']),
                    'level' => self::integer($targetPlayer['level']),
                    'floor' => self::integer($targetPlayer['current_floor']),
                    'location' => self::text($targetPlayer['current_location']),
                ];
            }
        }

        return [
            'party' => [
                'lobbyCode' => self::text($party['lobbyCode']),
                'lobbyType' => self::text($party['lobbyType']),
                'floor' => self::integer($party['floor']),
                'leaderId' => self::integer($party['leaderId']),
                'targetPlayerId' => $party['targetPlayerId'],
                'target' => $target,
                'members' => $party['members'],
                'capacity' => $party['lobbyType'] === 'defensive' ? 7 : 5,
                'teamPower' => $metrics['power'],
                'hasWarLord' => $metrics['hasWarLord'],
            ],
            'members' => $members,
        ];
    }

    /** @return array<string, mixed> */
    public function getSocial(int $playerId): array
    {
        $player = $this->readPlayer($playerId);
        $this->requireCity($player);
        $couple = $this->one('SELECT * FROM couples WHERE player_id_1 = ? OR player_id_2 = ?', [$playerId, $playerId]);
        $partner = null;
        if ($couple) {
            $partnerId = self::integer($couple['player_id_1']) === $playerId ? self::integer($couple['player_id_2']) : self::integer($couple['player_id_1']);
            // The Rubika social summary performs an optional player lookup:
            // a lingering couples row must still show its partner C_ID when
            // the player row has been removed or marked deleted. Do not use readPlayer() here,
            // because its active-player guard would turn that one stale link
            // into a failed social endpoint.
            $info = $this->one(
                'SELECT p.player_id, p.player_name, p.current_floor, p.current_location, p.is_deleted, c.class_name
                 FROM players p LEFT JOIN classes c ON c.class_id = p.class_id
                 WHERE p.player_id = ?',
                [$partnerId]
            );
            $partner = [
                'id' => $partnerId,
                'name' => $info ? self::text($info['player_name'], 'هیچ‌کس') : 'هیچ‌کس',
                'className' => $info ? self::text($info['class_name']) : null,
                'floor' => $info ? self::integer($info['current_floor']) : null,
                'location' => $info ? self::text($info['current_location']) : null,
                'recordFound' => $info !== null,
                'active' => $info !== null && !self::boolValue($info['is_deleted'] ?? false),
                'daysTogether' => self::integer($couple['days_together'] ?? 0),
                'battlesTogether' => self::integer($couple['battles_together'] ?? 0),
            ];
        }
        $guild = $this->one("SELECT g.*, gm.rank FROM guild_members gm JOIN guilds g ON g.guild_id = gm.guild_id WHERE gm.player_id = ? AND gm.status = 'accepted'", [$playerId]);
        // Match the social command's lightweight lobby scan. The detailed
        // Party endpoint independently resolves roster/power, while this
        // summary remains usable even if another member has vanished.
        $lobby = $this->lobbyForPlayer($playerId);
        $party = $lobby ? [
            'lobbyCode' => self::text($lobby['lobbyCode']),
            'lobbyType' => self::text($lobby['lobbyType']),
            'floor' => self::integer($lobby['floor']),
            'leaderId' => self::integer($lobby['leaderId']),
            'members' => $lobby['members'],
            'capacity' => $lobby['lobbyType'] === 'defensive' ? 7 : 5,
        ] : null;
        return [
            'partner' => $partner,
            'guild' => $guild ? ['id' => self::integer($guild['guild_id']), 'name' => self::text($guild['guild_name']), 'floor' => self::integer($guild['floor']), 'slogan' => self::text($guild['slogan'] ?? ''), 'leaderId' => self::integer($guild['leader_id']), 'rank' => self::text($guild['rank'])] : null,
            'party' => $party,
        ];
    }

    /** @return array<string, mixed>|null */
    public function getGuild(int $playerId): ?array
    {
        $player = $this->readPlayer($playerId);
        $this->requireCity($player);
        $membership = $this->one("SELECT g.*, gm.rank FROM guild_members gm JOIN guilds g ON g.guild_id = gm.guild_id WHERE gm.player_id = ? AND gm.status = 'accepted'", [$playerId]);
        if (!$membership) return null;
        $members = $this->rows("SELECT gm.player_id, gm.rank, p.player_name, p.level, c.class_name FROM guild_members gm JOIN players p ON p.player_id = gm.player_id LEFT JOIN classes c ON c.class_id = p.class_id WHERE gm.guild_id = ? AND gm.status = 'accepted' ORDER BY gm.rank = 'Leader' DESC, p.level DESC", [self::integer($membership['guild_id'])]);
        $pending = $this->rows("SELECT gm.player_id, p.player_name, p.level FROM guild_members gm JOIN players p ON p.player_id = gm.player_id WHERE gm.guild_id = ? AND gm.status = 'pending' ORDER BY gm.requested_at", [self::integer($membership['guild_id'])]);
        return [
            'id' => self::integer($membership['guild_id']), 'name' => self::text($membership['guild_name']), 'floor' => self::integer($membership['floor']), 'slogan' => self::text($membership['slogan'] ?? ''), 'leaderId' => self::integer($membership['leader_id']), 'rank' => self::text($membership['rank']),
            'members' => array_map(function (array $member): array { return ['id' => self::integer($member['player_id']), 'name' => self::text($member['player_name']), 'level' => self::integer($member['level']), 'className' => self::text($member['class_name']), 'rank' => self::text($member['rank'])]; }, $members),
            'pending' => array_map(function (array $member): array { return ['id' => self::integer($member['player_id']), 'name' => self::text($member['player_name']), 'level' => self::integer($member['level'])]; }, $pending),
        ];
    }

    /** @return array<string, mixed> */
    public function leaderboard(string $kind, int $page = 1): array
    {
        $perPage = 10;
        $safePage = max(1, (int) floor($page));
        $offset = ($safePage - 1) * $perPage;
        if ($kind === 'players') {
            $totalRow = $this->one('SELECT COUNT(*) AS total FROM players WHERE is_deleted = FALSE');
            $total = self::integer($totalRow['total'] ?? 0);
            $rows = $this->rows('SELECT player_name, level, experience FROM players WHERE is_deleted = FALSE ORDER BY level DESC, experience DESC LIMIT ? OFFSET ?', [$perPage, $offset]);
            return ['title' => 'برترین بازیکنان (بر اساس سطح)', 'page' => $safePage, 'totalPages' => max(1, (int) ceil($total / $perPage)), 'rows' => array_map(function (array $row, int $index) use ($offset): array { return ['rank' => $offset + $index + 1, 'name' => self::text($row['player_name']), 'level' => self::integer($row['level']), 'experience' => self::integer($row['experience'])]; }, $rows, array_keys($rows))];
        }
        if ($kind === 'killers') {
            $totalRow = $this->one('SELECT COUNT(*) AS total FROM players WHERE is_deleted = FALSE AND kills > 0');
            $total = self::integer($totalRow['total'] ?? 0);
            $rows = $this->rows('SELECT player_name, kills, pk_status FROM players WHERE is_deleted = FALSE AND kills > 0 ORDER BY kills DESC LIMIT ? OFFSET ?', [$perPage, $offset]);
            return ['title' => 'برترین قاتلان', 'page' => $safePage, 'totalPages' => max(1, (int) ceil($total / $perPage)), 'rows' => array_map(function (array $row, int $index) use ($offset): array { return ['rank' => $offset + $index + 1, 'name' => self::text($row['player_name']), 'kills' => self::integer($row['kills']), 'pkStatus' => self::text($row['pk_status'])]; }, $rows, array_keys($rows))];
        }
        if ($kind === 'guilds') {
            $totalRow = $this->one('SELECT COUNT(*) AS total FROM guilds');
            $total = self::integer($totalRow['total'] ?? 0);
            // The native hall identifies the guild leader alongside its
            // member count and aggregate level. Join it once rather than
            // issuing the bot's per-row lookup.
            $rows = $this->rows("SELECT g.guild_name, leader.player_name AS leader_name, COUNT(gm.player_id) AS member_count, COALESCE(SUM(p.level), 0) AS total_level FROM guilds g LEFT JOIN players leader ON leader.player_id = g.leader_id LEFT JOIN guild_members gm ON g.guild_id = gm.guild_id AND gm.status = 'accepted' LEFT JOIN players p ON p.player_id = gm.player_id GROUP BY g.guild_id, g.guild_name, leader.player_name ORDER BY total_level DESC, member_count DESC LIMIT ? OFFSET ?", [$perPage, $offset]);
            return ['title' => 'برترین گیلدها', 'page' => $safePage, 'totalPages' => max(1, (int) ceil($total / $perPage)), 'rows' => array_map(function (array $row, int $index) use ($offset): array { return ['rank' => $offset + $index + 1, 'name' => self::text($row['guild_name']), 'leader' => self::text($row['leader_name'] ?? null, 'نامشخص'), 'members' => self::integer($row['member_count']), 'totalLevel' => self::integer($row['total_level'])]; }, $rows, array_keys($rows))];
        }
        if ($kind === 'groups') {
            $totalRow = $this->one('SELECT COUNT(*) AS total FROM bot_groups');
            $total = self::integer($totalRow['total'] ?? 0);
            $rows = $this->rows('SELECT bg.group_name, COUNT(gp.player_id) AS member_count, COALESCE(SUM(p.level), 0) AS total_level FROM bot_groups bg LEFT JOIN group_players gp ON bg.group_id = gp.group_id LEFT JOIN players p ON p.player_id = gp.player_id GROUP BY bg.group_id, bg.group_name ORDER BY total_level DESC, member_count DESC LIMIT ? OFFSET ?', [$perPage, $offset]);
            return ['title' => 'برترین گروه‌ها', 'page' => $safePage, 'totalPages' => max(1, (int) ceil($total / $perPage)), 'rows' => array_map(function (array $row, int $index) use ($offset): array { return ['rank' => $offset + $index + 1, 'name' => self::text($row['group_name'] ?? null, 'گروه بی‌نام'), 'members' => self::integer($row['member_count']), 'totalLevel' => self::integer($row['total_level'])]; }, $rows, array_keys($rows))];
        }
        throw new GameException('نوع جدول برترین‌ها نامعتبر است.');
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed> */
    public function action(int $playerId, string $actionName, array $payload = []): array
    {
        switch ($actionName) {
            case 'claim-daily': return $this->claimDaily($playerId);
            case 'exit-city': return $this->exitCity($playerId);
            case 'return-city': return $this->returnCity($playerId, false);
            case 'force-return-city': return $this->returnCity($playerId, true);
            case 'teleport': return $this->teleport($playerId, $payload['floor'] ?? null);
            case 'hunt': return $this->hunt($playerId);
            case 'dungeon': return $this->dungeon($playerId);
            case 'boss-fight': return $this->bossFight($playerId);
            case 'spy-boss': return $this->spyBoss($playerId);
            case 'pvp': return $this->pvp($playerId, $payload['targetId'] ?? null);
            case 'spy-player': return $this->spyPlayer($playerId, $payload['targetId'] ?? null);
            case 'buy-item': return $this->buyItem($playerId, $payload['itemId'] ?? null);
            case 'buy-crafted': return $this->buyCrafted($playerId, $payload['craftItemId'] ?? null);
            case 'sell-item': return $this->sellItem($playerId, $payload['itemId'] ?? null);
            case 'equip': return $this->equip($playerId, $payload['slot'] ?? null, $payload['itemId'] ?? null, $payload['instanceId'] ?? null);
            case 'unequip': return $this->unequip($playerId);
            case 'summon-pet': return $this->summonPet($playerId, $payload['itemId'] ?? null);
            case 'rest-pet': return $this->restPet($playerId);
            case 'transfer-coins': return $this->transferCoins($playerId, $payload['targetId'] ?? null, $payload['amount'] ?? null, self::boolValue($payload['partnerGift'] ?? false));
            case 'transfer-item': return $this->transferItem($playerId, $payload['targetId'] ?? null, $payload['itemId'] ?? null, $payload['quantity'] ?? null);
            case 'transfer-mini': return $this->transferMini($playerId, $payload['targetId'] ?? null, $payload['miniItemId'] ?? null, $payload['quantity'] ?? null);
            case 'transfer-crafted': return $this->transferCrafted($playerId, $payload['targetId'] ?? null, $payload['instanceId'] ?? null);
            case 'drop-item': return $this->dropItem($playerId, $payload['itemId'] ?? null, $payload['quantity'] ?? null);
            case 'drop-mini': return $this->dropMini($playerId, $payload['miniItemId'] ?? null, $payload['quantity'] ?? null);
            case 'drop-crafted': return $this->dropCrafted($playerId, $payload['instanceId'] ?? null);
            case 'accept-daily': return $this->acceptDailyQuest($playerId, $payload['questId'] ?? null);
            case 'create-party': return $this->createParty($playerId);
            case 'create-team-pvp': return $this->createTeamPvp($playerId, $payload['targetId'] ?? null);
            case 'create-team-boss': return $this->createTeamBoss($playerId);
            case 'join-party': return $this->joinParty($playerId, $payload['lobbyCode'] ?? null);
            case 'start-party-battle': return $this->startPartyBattle($playerId);
            case 'leave-party': return $this->leaveParty($playerId);
            case 'disband-party': return $this->disbandParty($playerId);
            case 'invite-partner': return $this->invitePartner($playerId, $payload['targetId'] ?? null);
            case 'accept-partner': return $this->acceptPartner($playerId, $payload['partnerId'] ?? null);
            case 'remove-partner': return $this->removePartner($playerId);
            case 'create-guild': return $this->createGuild($playerId, $payload['name'] ?? null);
            case 'join-guild': return $this->joinGuild($playerId, $payload['guildId'] ?? null);
            case 'guild-request': return $this->guildRequest($playerId, $payload['targetId'] ?? null, self::boolValue($payload['approve'] ?? false));
            case 'guild-kick': return $this->guildKick($playerId, $payload['targetId'] ?? null);
            case 'guild-rank': return $this->guildRank($playerId, $payload['targetId'] ?? null, $payload['rank'] ?? null);
            case 'guild-slogan': return $this->guildSlogan($playerId, $payload['slogan'] ?? null);
            case 'guild-transfer': return $this->guildTransfer($playerId, $payload['targetId'] ?? null);
            case 'guild-disband': return $this->guildDisband($playerId, $payload['confirmation'] ?? null);
            case 'guild-leave': return $this->guildLeave($playerId);
            case 'craft': return $this->craft($playerId, $payload['craftItemId'] ?? null);
            case 'upgrade': return $this->upgrade($playerId, $payload['instanceId'] ?? null);
            case 'collect-crafting': return $this->collectCrafting($playerId);
            case 'create-bag-invoice': return $this->createBagInvoice($playerId);
            case 'create-noble-invoice': return $this->createNobleInvoice($playerId);
            case 'create-crafted-invoice': return $this->buyCrafted($playerId, $payload['craftItemId'] ?? null);
            default: throw new GameException('فرمان بازی شناخته نشد.');
        }
    }

    /** @return array<string, mixed> */
    private function claimDaily(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $reward = $this->one('SELECT last_claim_date FROM daily_rewards WHERE player_id = ?', [$playerId]);
            if ($reward && self::sameDay($reward['last_claim_date'] ?? null)) throw new GameException('سهمیه جوایز روزانه امروز قبلاً دریافت شده است.');
            $noble = $this->isNoble($player);
            $coins = $noble ? 2500 : 1000;
            $xp = $noble ? 1200 : 500;
            $items = [];
            if ($noble && self::random() < 0.4 && $this->hasInventorySpace($player)) {
                $options = $this->rows('SELECT item_id, item_name FROM items WHERE type_id IN (1, 2, 3) AND is_available = TRUE ORDER BY RAND() LIMIT 1');
                if ($options) {
                    $this->addInventory($playerId, self::integer($options[0]['item_id']));
                    $items[] = self::text($options[0]['item_name']);
                }
            }
            $this->execute('UPDATE players SET coins = coins + ?, experience = experience + ? WHERE player_id = ?', [$coins, $xp, $playerId]);
            $this->execute('INSERT INTO daily_rewards (player_id, last_claim_date, claimed_today) VALUES (?, CURDATE(), TRUE) ON DUPLICATE KEY UPDATE last_claim_date = CURDATE(), claimed_today = TRUE', [$playerId]);
            return self::event('success', $noble ? 'هدیه روزانه اشرافی' : 'هدیه روزانه کاردینال', 'سهمیه امروز با موفقیت به حساب شما واریز شد.', ['rewards' => ['coins' => $coins, 'xp' => $xp, 'items' => $items]]);
        });
    }

    /** @return array<string, mixed> */
    private function exitCity(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') === 'wild') throw new GameException('شما هم‌اکنون بیرون از شهر هستید.');
            $returnAt = self::mysqlDate(self::now()->modify('+6 hours'));
            $this->execute("UPDATE players SET current_location = 'wild', city_entry_cooldown_until = ? WHERE player_id = ?", [$returnAt, $playerId]);
            return self::event('warning', 'خروج از شهر', 'شما وارد منطقه ناامن طبقه ' . self::integer($player['current_floor']) . ' شدید. تا پایان زمان مسیر، بازگشت فوری فقط با کریستال ممکن است.');
        });
    }

    /** @return array<string, mixed> */
    private function returnCity(int $playerId, bool $forced): array
    {
        return $this->transaction(function () use ($playerId, $forced): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') === 'city') throw new GameException('شما هم‌اکنون در منطقه امن شهر هستید.');
            $cityCooldown = self::dateValue($player['city_entry_cooldown_until'] ?? null);
            $now = self::now();
            $blocked = !$this->isNoble($player) && $cityCooldown && $cityCooldown > $now;
            $forcedCost = self::integer($player['current_floor']) * 100;
            if ($forced && (!$cityCooldown || $cityCooldown <= $now)) {
                throw new GameException('در حال حاضر نیازی به کریستال تلپورت اجباری ندارید؛ می‌توانید به‌صورت عادی به شهر بازگردید.');
            }
            if ($blocked && !$forced) {
                $minutes = max(0, (int) floor(($cityCooldown->getTimestamp() - self::now()->getTimestamp()) / 60));
                throw new GameException('مسیر بازگشت هنوز ' . (int) floor($minutes / 60) . ' ساعت و ' . ($minutes % 60) . ' دقیقه باقی مانده است. برای بازگشت فوری، کریستال اجباری با هزینه ' . $forcedCost . 'C استفاده کنید.');
            }
            $fee = $forced ? $forcedCost : ($this->isNoble($player) ? 0 : self::integer($player['current_floor']) * 400);
            if (self::integer($player['coins']) < $fee) throw new GameException('موجودی سکه کافی نیست؛ هزینه بازگشت ' . $fee . 'C است.');
            $this->execute("UPDATE players SET current_location = 'city', coins = coins - ?, city_entry_cooldown_until = ? WHERE player_id = ?", [$fee, $forced ? null : ($player['city_entry_cooldown_until'] ?? null), $playerId]);
            return self::event('success', $forced ? 'کریستال تلپورت فعال شد' : 'ورود به منطقه امن', 'به شهر طبقه ' . self::integer($player['current_floor']) . ' بازگشتید.', ['rewards' => ['coins' => -$fee]]);
        });
    }

    /** @return array<string, mixed> */
    private function teleport(int $playerId, $target): array
    {
        $targetFloor = self::assertInt($target, 'شماره طبقه', 1, 100);
        return $this->transaction(function () use ($playerId, $targetFloor): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'city') throw new GameException('تلپورت فقط از داخل شهر امکان‌پذیر است.');
            if ($targetFloor > self::integer($player['last_floor_unlocked'])) throw new GameException('قفل طبقه ' . $targetFloor . ' هنوز باز نشده است.');
            if ($targetFloor === self::integer($player['current_floor'])) throw new GameException('شما هم‌اکنون در همین طبقه هستید.');
            $cost = $this->isNoble($player) ? 0 : abs($targetFloor - self::integer($player['current_floor'])) * 100;
            if (self::integer($player['coins']) < $cost) throw new GameException('موجودی کافی نیست؛ هزینه این تلپورت ' . $cost . 'C است.');
            $this->execute('UPDATE players SET current_floor = ?, coins = coins - ? WHERE player_id = ?', [$targetFloor, $cost, $playerId]);
            $this->removeWrongFloorLobbies($playerId, $targetFloor);
            return self::event('success', 'انتقال موفق', 'شما به شهر طبقه ' . $targetFloor . ' منتقل شدید.', ['rewards' => ['coins' => -$cost]]);
        });
    }

    /** @return array<string, mixed> */
    private function hunt(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'wild') throw new GameException('برای شکار هیولا ابتدا از شهر خارج شوید.');
            $cooldown = self::dateValue($player['hunt_lock_until'] ?? null);
            if ($cooldown && $cooldown > self::now()) throw new GameException('زمان باقی‌مانده تا شکار بعدی: ' . (int) ceil($cooldown->getTimestamp() - self::now()->getTimestamp()) . ' ثانیه.');
            $monsters = $this->rows('SELECT * FROM monsters WHERE floor = ? AND is_dungeon_monster = FALSE', [self::integer($player['current_floor'])]);
            if (!$monsters) throw new GameException('هیولایی در این طبقه ردیابی نشد.');
            $monster = $monsters[(int) floor(self::random() * count($monsters))];
            $this->execute('UPDATE players SET hunt_lock_until = ? WHERE player_id = ?', [self::mysqlDate(self::now()->modify('+10 minutes')), $playerId]);
            $power = $this->battlePower($playerId);
            if ($power < self::num($monster['level'] ?? 0)) {
                $lost = (int) floor(self::num($player['coins'] ?? 0) * 0.1);
                $this->execute('UPDATE players SET coins = coins - ? WHERE player_id = ?', [$lost, $playerId]);
                return self::event('danger', 'شکار ناموفق', self::text($monster['monster_name']) . ' قدرتی فراتر از انتظار داشت.', ['rewards' => ['coins' => -$lost]]);
            }
            $xp = ($player['class_name'] ?? '') === 'Warrior' ? (int) floor(self::num($monster['reward_xp'] ?? 0) * 1.1) : self::num($monster['reward_xp'] ?? 0);
            $coins = CardinalRules::merchantCoinReward($player['class_name'] ?? null, self::num($monster['reward_coins'] ?? 0));
            $this->execute('UPDATE players SET monsters_killed = monsters_killed + 1, coins = coins + ?, experience = experience + ? WHERE player_id = ?', [$coins, $xp, $playerId]);
            $this->advanceRegularQuests($playerId, 'kill_monsters');
            $this->advanceDailyQuest($playerId, 'monster', self::text($monster['monster_name']));
            $miniItems = $this->rollMiniDrops('monster', self::integer($monster['monster_id']), $player);
            $items = $this->rollRegularDrops($player, false);
            return self::event('success', 'شکار موفق', self::text($monster['monster_name']) . ' شکست خورد.', ['rewards' => ['coins' => $coins, 'xp' => $xp, 'items' => $items, 'miniItems' => $miniItems]]);
        });
    }

    /** @return array<string, mixed> */
    private function dungeon(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'city') throw new GameException('ورود به سیاه‌چال مخفی فقط از شهر ممکن است.');
            if (!$this->isNoble($player)) throw new GameException('سیاه‌چال مخفی تنها برای دارندگان نشان اشراف فعال است.', 403);
            $cooldown = self::dateValue($player['dungeon_cooldown_until'] ?? null);
            if ($cooldown && $cooldown > self::now()) throw new GameException('برای ورود دوباره باید ' . (int) ceil(($cooldown->getTimestamp() - self::now()->getTimestamp()) / 60) . ' دقیقه استراحت کنید.');
            $monsters = $this->rows('SELECT * FROM monsters WHERE floor = ? AND is_dungeon_monster = TRUE', [self::integer($player['current_floor'])]);
            if (!$monsters) $monsters = $this->rows('SELECT * FROM monsters WHERE floor = ?', [self::integer($player['current_floor'])]);
            $monster = $monsters ? $monsters[(int) floor(self::random() * count($monsters))] : null;
            $finishes = self::mysqlDate(self::now()->modify('+7 minutes'));
            if (self::random() >= 0.7) {
                $this->execute('UPDATE players SET dungeon_cooldown_until = ? WHERE player_id = ?', [$finishes, $playerId]);
                return self::event('danger', 'کاوش ناموفق', 'در اعماق دانجن مقابل ' . self::text($monster['monster_name'] ?? null, 'هیولای مرموز') . ' شکست خوردید، اما چیزی از دست ندادید.');
            }
            $xp = ($player['class_name'] ?? '') === 'Warrior' ? (int) floor(self::num($monster['reward_xp'] ?? 1000, 1000) * 1.1) : self::num($monster['reward_xp'] ?? 1000, 1000);
            $coins = CardinalRules::merchantCoinReward($player['class_name'] ?? null, self::num($monster['reward_coins'] ?? 500, 500));
            $this->execute('UPDATE players SET dungeon_cooldown_until = ?, coins = coins + ?, experience = experience + ? WHERE player_id = ?', [$finishes, $coins, $xp, $playerId]);
            $this->advanceRegularQuests($playerId, 'dungeon_kills');
            if ($monster) $this->advanceDailyQuest($playerId, 'monster', self::text($monster['monster_name']));
            $miniItems = $monster ? $this->rollMiniDrops('monster', self::integer($monster['monster_id']), $player) : [];
            $items = [];
            if (self::random() < 0.5) {
                $options = $this->rows('SELECT item_id, item_name FROM items WHERE type_id IN (1, 2, 3) AND is_available = TRUE');
                if ($options) { $selected = $options[(int) floor(self::random() * count($options))]; $this->addInventory($playerId, self::integer($selected['item_id'])); $items[] = self::text($selected['item_name']); }
            }
            if (self::random() < 0.2) {
                $pets = $this->rows('SELECT item_id, item_name FROM items WHERE type_id = 4 AND is_available = TRUE');
                if ($pets) { $selected = $pets[(int) floor(self::random() * count($pets))]; $this->addInventory($playerId, self::integer($selected['item_id'])); $items[] = '🐾 ' . self::text($selected['item_name']); }
            }
            return self::event('success', 'کاوش موفق', self::text($monster['monster_name'] ?? null, 'هیولای مرموز') . ' در دانجن شکست خورد.', ['rewards' => ['coins' => $coins, 'xp' => $xp, 'items' => $items, 'miniItems' => $miniItems]]);
        });
    }

    /** @param array<string, mixed> $target */
    private function defensiveTeamPower(array $target): int
    {
        $lobby = null;
        foreach ($this->getLobbies() as $candidate) {
            if ($candidate['lobbyType'] === 'defensive' && self::integer($candidate['floor']) === self::integer($target['current_floor']) && in_array(self::integer($target['player_id']), $candidate['members'], true)) {
                $lobby = $candidate;
                break;
            }
        }
        if (!$lobby) return $this->battlePower(self::integer($target['player_id']), true);
        $total = 0;
        $hasWarLord = false;
        foreach ($lobby['members'] as $id) {
            $total += $this->battlePower(self::integer($id));
            $member = $this->readPlayer(self::integer($id));
            if (($member['class_name'] ?? '') === 'War Lord') $hasWarLord = true;
        }
        return $hasWarLord ? (int) floor($total * 1.25) : $total;
    }

    /** @return array<int, string> */
    private function lootFromPlayer(int $winnerId, int $loserId): array
    {
        $items = $this->rows(
            'SELECT inv.item_id, inv.quantity, i.item_name FROM inventory inv JOIN items i ON i.item_id = inv.item_id LEFT JOIN equipment e ON e.player_id = inv.player_id AND (inv.item_id = e.weapon_id OR inv.item_id = e.armor_id OR inv.item_id = e.pet_id) WHERE inv.player_id = ? AND e.player_id IS NULL AND inv.item_id != 4',
            [$loserId]
        );
        $looted = [];
        foreach ($items as $item) {
            if (self::random() < 0.25) {
                $quantity = max(1, (int) floor(self::num($item['quantity']) * 0.25));
                $this->deductInventory($loserId, self::integer($item['item_id']), $quantity);
                $this->addInventory($winnerId, self::integer($item['item_id']), $quantity);
                $looted[] = self::text($item['item_name']) . ' ×' . $quantity;
            }
        }
        $equipment = $this->one('SELECT pet_id FROM equipment WHERE player_id = ?', [$loserId]);
        if ($equipment && !empty($equipment['pet_id']) && self::random() < 0.15) {
            $pet = $this->one('SELECT item_name FROM items WHERE item_id = ?', [self::integer($equipment['pet_id'])]);
            $this->execute('UPDATE equipment SET pet_id = NULL WHERE player_id = ?', [$loserId]);
            $this->addInventory($winnerId, self::integer($equipment['pet_id']));
            $looted[] = '🐾 ' . self::text($pet['item_name'] ?? null, 'حیوان همراه');
        }
        return $looted;
    }

    /** @return array<string, mixed> */
    private function spyPlayer(int $playerId, $targetInput): array
    {
        $targetId = self::assertInt($targetInput, 'شناسه بازیکن هدف');
        if ($targetId === $playerId) throw new GameException('شما نمی‌توانید از آواتار خودتان جاسوسی کنید.');
        return $this->transaction(function () use ($playerId, $targetId): array {
            $player = $this->preparePlayer($playerId, true);
            if (self::integer($player['class_id']) !== 4) throw new GameException('فقط بازیکنان کلاس قاتل مهارت جاسوسی دارند.', 403);
            $target = $this->readPlayer($targetId, true);
            if (self::integer($target['level']) > self::integer($player['level'])) {
                throw new GameException('سطح هدف از سطح شما بالاتر است و ردگیری او ممکن نیست.');
            }
            $this->queueNotification($target, '⚠️ هشدار امنیتی: یک قاتل ناشناس از شما جاسوسی کرد؛ مراقب باشید.');
            return self::event(
                'info',
                'گزارش جاسوسی موفق',
                'رد آواتار «' . self::text($target['player_name']) . '» در شبکه کاردینال پیدا شد.',
                ['spyReport' => [
                    'id' => self::integer($target['player_id']),
                    'name' => self::text($target['player_name']),
                    'level' => self::integer($target['level']),
                    'coins' => self::integer($target['coins']),
                    'floor' => self::integer($target['current_floor']),
                    'pkStatus' => self::text($target['pk_status']),
                ]]
            );
        });
    }

    /** @return array<string, mixed> */
    private function pvp(int $playerId, $targetInput): array
    {
        $targetId = self::assertInt($targetInput, 'شناسه هدف');
        if ($targetId === $playerId) throw new GameException('شما نمی‌توانید به آواتار خودتان حمله کنید.');
        return $this->transaction(function () use ($playerId, $targetId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'wild') throw new GameException('برای حمله به بازیکن ابتدا از شهر خارج شوید.');
            $target = $this->readPlayer($targetId, true);
            if (($target['current_location'] ?? '') === 'city') throw new GameException('بازیکن هدف در منطقه امن شهر است.');
            if (self::integer($target['current_floor']) !== self::integer($player['current_floor'])) throw new GameException('بازیکن هدف در طبقه ' . self::integer($target['current_floor']) . ' است.');
            $attackerPower = $this->battlePower($playerId, true);
            $defenderPower = $this->defensiveTeamPower($target);
            if ($attackerPower < $defenderPower) {
                $lost = (int) floor(self::num($player['coins']) * 0.15);
                $this->execute("UPDATE players SET coins = coins - ?, deaths = deaths + 1, current_floor = 1, current_location = 'city' WHERE player_id = ?", [$lost, $playerId]);
                $this->removeWrongFloorLobbies($playerId, 1);
                return self::event('danger', 'شکست در نبرد PvP', 'قدرت دفاعی هدف (' . $defenderPower . ') بیشتر از قدرت شما (' . $attackerPower . ') بود.', ['rewards' => ['coins' => -$lost]]);
            }
            $bounty = in_array(self::text($target['pk_status'] ?? ''), ['yellow', 'red', 'black'], true);
            $nextKills = self::integer($player['kills'] ?? 0) + ($bounty ? 0 : 1);
            $nextStatus = CardinalRules::pkDetails($nextKills)['status'];
            $lossPercent = CardinalRules::pkDetails(self::integer($target['kills'] ?? 0))['percent'];
            $lootCoins = (int) floor(self::num($target['coins']) * $lossPercent / 100);
            $this->execute('UPDATE players SET coins = coins + ?, kills = ?, pk_status = ? WHERE player_id = ?', [$lootCoins, $nextKills, $nextStatus, $playerId]);
            if (in_array(self::text($target['pk_status'] ?? ''), ['yellow', 'red', 'black'], true)) $this->execute('UPDATE players SET level = GREATEST(1, level - 1), kills = GREATEST(0, kills - 10) WHERE player_id = ?', [$targetId]);
            $this->execute("UPDATE players SET coins = coins - ?, deaths = deaths + 1, current_floor = 1, current_location = 'city', pk_status = 'white' WHERE player_id = ?", [$lootCoins, $targetId]);
            $this->removeWrongFloorLobbies($targetId, 1);
            $items = $this->lootFromPlayer($playerId, $targetId);
            $this->notifyPartnerOfAttack($targetId, self::text($player['player_name']), $playerId, self::integer($target['current_floor']), $items);
            $this->advanceRegularQuests($playerId, 'pvp_kills');
            $this->advanceRegularQuests($targetId, 'pvp_deaths');
            $this->queueNotification($target, '🩸 شما توسط ' . self::text($player['player_name']) . ' شکست خوردید. ' . $lootCoins . 'C از دست رفت و به طبقه ۱ منتقل شدید.');
            return self::event('success', 'پیروزی در نبرد PvP', self::text($target['player_name']) . ' شکست خورد.', ['rewards' => ['coins' => $lootCoins, 'items' => $items]]);
        });
    }

    /** @return array<string, mixed> */
    private function spyBoss(int $playerId): array
    {
        $player = $this->readPlayer($playerId);
        $this->requireWild($player);
        if (self::integer($player['class_id']) !== 4) {
            throw new GameException('فقط بازیکنان کلاس قاتل مهارت جاسوسی از رئیس طبقه را دارند.', 403);
        }
        $boss = $this->one('SELECT * FROM bosses WHERE floor = ?', [self::integer($player['current_floor'])]);
        if (!$boss) throw new GameException('غول این طبقه قبلاً شکست خورده یا پیدا نشد.', 404);
        $report = [
            'floor' => self::integer($player['current_floor']),
            'name' => self::text($boss['boss_name']),
            'description' => self::text($boss['description'] ?? ''),
            'level' => self::integer($boss['level']),
            'battlePower' => self::integer($boss['level']) * 10,
        ];
        return self::event(
            'info',
            'گزارش جاسوسی رئیس طبقه',
            'اسکن مخفی «' . $report['name'] . '» با موفقیت انجام شد.',
            ['bossSpyReport' => $report, 'refresh' => false]
        );
    }

    /** @return array<string, mixed> */
    private function bossFight(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'wild') throw new GameException('تالار باس در بیرون شهر قرار دارد.');
            $cooldown = self::dateValue($player['lock_until'] ?? null);
            if ($cooldown && $cooldown > self::now()) throw new GameException('تا ورود دوباره به تالار باس ' . (int) ceil($cooldown->getTimestamp() - self::now()->getTimestamp()) . ' ثانیه باقی مانده است.');
            $boss = $this->one('SELECT * FROM bosses WHERE floor = ?', [self::integer($player['current_floor'])]);
            if (!$boss) throw new GameException('باس این طبقه پیدا نشد.');
            $this->execute('UPDATE players SET lock_until = ? WHERE player_id = ?', [self::mysqlDate(self::now()->modify('+15 minutes')), $playerId]);
            $power = $this->battlePower($playerId);
            if ($power < self::integer($boss['level']) * 10) {
                $lost = (int) floor(self::num($player['coins']) * 0.2);
                $this->execute("UPDATE players SET current_floor = 1, current_location = 'city', coins = coins - ? WHERE player_id = ?", [$lost, $playerId]);
                $this->removeWrongFloorLobbies($playerId, 1);
                return self::event('danger', 'شکست در تالار نهایی', self::text($boss['boss_name']) . ' قدرتی فراتر از شما داشت.', ['rewards' => ['coins' => -$lost]]);
            }
            $coins = CardinalRules::merchantCoinReward($player['class_name'] ?? null, self::num($boss['reward_coins'] ?? 0));
            $miniItems = $this->rollMiniDrops('boss', self::integer($boss['boss_id'] ?? ($boss['id'] ?? $boss['floor'])), $player);
            $items = $this->rollRegularDrops($player, true);
            $this->advanceDailyQuest($playerId, 'boss', self::text($boss['boss_name']));
            if (self::integer($player['current_floor']) === self::integer($player['last_floor_unlocked'])) {
                $this->execute('UPDATE players SET current_floor = current_floor + 1, last_floor_unlocked = last_floor_unlocked + 1, coins = coins + ? WHERE player_id = ?', [$coins, $playerId]);
                $this->removeWrongFloorLobbies($playerId, self::integer($player['current_floor']) + 1);
                return self::event('success', 'فتح طبقه ' . self::integer($player['current_floor']), self::text($boss['boss_name']) . ' شکست خورد و طبقه بعدی باز شد.', ['rewards' => ['coins' => $coins, 'items' => $items, 'miniItems' => $miniItems]]);
            }
            $this->execute('UPDATE players SET coins = coins + ? WHERE player_id = ?', [$coins, $playerId]);
            return self::event('success', 'نبرد مجدد موفق', self::text($boss['boss_name']) . ' بار دیگر شکست خورد.', ['rewards' => ['coins' => $coins, 'items' => $items, 'miniItems' => $miniItems]]);
        });
    }

    /** @return array<string, mixed> */
    private function buyItem(int $playerId, $itemInput): array
    {
        $itemId = self::assertInt($itemInput, 'شناسه آیتم');
        return $this->transaction(function () use ($playerId, $itemId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'city') throw new GameException('خرید از فروشگاه فقط در شهر ممکن است.');
            $item = $this->one('SELECT * FROM items WHERE item_id = ? AND is_available = TRUE', [$itemId]);
            if (!$item || empty($item['price_coins'])) throw new GameException('این آیتم با سکه قابل خرید نیست.');
            if (!$this->hasInventorySpace($player)) throw new GameException('ظرفیت کوله‌پشتی شما پر است.');
            $price = self::integer($item['price_coins']);
            if (($player['class_name'] ?? '') === 'Blacksmith') $price = (int) floor($price * 0.8);
            elseif (($player['class_name'] ?? '') === 'Merchant') $price = (int) floor($price * 0.95);
            if (self::integer($player['coins']) < $price) throw new GameException('سکه کافی نیست؛ به ' . $price . 'C نیاز دارید.');
            $this->execute('UPDATE players SET coins = coins - ? WHERE player_id = ?', [$price, $playerId]);
            $this->addInventory($playerId, $itemId);
            return self::event('success', 'خرید موفق', '«' . self::text($item['item_name']) . '» به کوله‌پشتی شما اضافه شد.', ['rewards' => ['coins' => -$price, 'items' => [self::text($item['item_name'])]]]);
        });
    }

    /** @return array<string, mixed> */
    private function buyCrafted(int $playerId, $craftInput): array
    {
        $craftItemId = self::assertInt($craftInput, 'شناسه نقشه');
        return $this->transaction(function () use ($playerId, $craftItemId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'city') throw new GameException('خرید از فروشگاه فقط در شهر ممکن است.');
            $craft = $this->one('SELECT * FROM craftable_items WHERE craft_item_id = ?', [$craftItemId]);
            if (!$craft) throw new GameException('آیتم ساخته‌شده یافت نشد.');
            if (self::integer($craft['floor']) !== self::integer($player['current_floor'])) throw new GameException('این آیتم مخصوص طبقه ' . self::integer($craft['floor']) . ' است.');
            if (!$this->hasInventorySpace($player)) throw new GameException('ظرفیت کوله‌پشتی شما پر است.');
            if (self::text($craft['price_type'] ?? 'coins', 'coins') !== 'coins') {
                $result = $this->execute('INSERT INTO pending_payments (player_id, item_name, amount, craft_item_id) VALUES (?, ?, ?, ?)', [$playerId, self::text($craft['item_name']), self::num($craft['base_price']), $craftItemId]);
                return self::event('info', 'فاکتور پرداخت ثبت شد', 'درخواست «' . self::text($craft['item_name']) . '» با شناسه فاکتور ' . $result['insertId'] . ' ثبت شد. تأیید آن با همان فرایند دستی فعلی انجام می‌شود.', ['refresh' => false]);
            }
            $price = self::integer($craft['base_price']);
            if (($player['class_name'] ?? '') === 'Blacksmith') $price = (int) floor($price * 0.8);
            elseif (($player['class_name'] ?? '') === 'Merchant') $price = (int) floor($price * 0.95);
            if (self::integer($player['coins']) < $price) throw new GameException('سکه کافی نیست؛ به ' . $price . 'C نیاز دارید.');
            $this->execute('UPDATE players SET coins = coins - ? WHERE player_id = ?', [$price, $playerId]);
            $this->execute('INSERT INTO player_crafted_items (craft_item_id, owner_player_id, current_power, upgrade_level) VALUES (?, ?, ?, 0)', [$craftItemId, $playerId, self::num($craft['base_power'])]);
            return self::event('success', 'خرید موفق', '«' . self::text($craft['item_name']) . '» به دارایی‌های ساخته‌شده شما اضافه شد.', ['rewards' => ['coins' => -$price, 'items' => [self::text($craft['item_name'])]]]);
        });
    }

    /** @return array<string, mixed> */
    private function sellItem(int $playerId, $itemInput): array
    {
        $itemId = self::assertInt($itemInput, 'شناسه آیتم');
        return $this->transaction(function () use ($playerId, $itemId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'city') throw new GameException('فروش آیتم فقط در شهر ممکن است.');
            $item = $this->one('SELECT * FROM items WHERE item_id = ?', [$itemId]);
            if (!$item || $itemId === 4) throw new GameException('این آیتم قابل فروش نیست.');
            $equipment = $this->one('SELECT player_id FROM equipment WHERE player_id = ? AND (weapon_id = ? OR armor_id = ? OR pet_id = ?)', [$playerId, $itemId, $itemId, $itemId]);
            if ($equipment) throw new GameException('ابتدا این آیتم را از تجهیزات خارج کنید.');
            $this->deductInventory($playerId, $itemId, 1);
            $price = !empty($item['price_coins']) ? (int) floor(self::num($item['price_coins']) / 2) : 50;
            $this->execute('UPDATE players SET coins = coins + ? WHERE player_id = ?', [$price, $playerId]);
            return self::event('success', 'فروش موفق', 'یک عدد «' . self::text($item['item_name']) . '» فروخته شد.', ['rewards' => ['coins' => $price]]);
        });
    }

    /** @return array<string, mixed> */
    private function equip(int $playerId, $slotInput, $itemInput, $instanceInput): array
    {
        $slot = self::text($slotInput);
        if ($slot !== 'weapon' && $slot !== 'armor') throw new GameException('نوع تجهیزات نامعتبر است.');
        return $this->transaction(function () use ($playerId, $slot, $itemInput, $instanceInput): array {
            $this->preparePlayer($playerId, true);
            $field = $slot === 'weapon' ? 'weapon_id' : 'armor_id';
            $craftedField = $slot === 'weapon' ? 'crafted_weapon_instance_id' : 'crafted_armor_instance_id';
            if ($instanceInput !== null && $instanceInput !== '') {
                $instanceId = self::assertInt($instanceInput, 'شناسه آیتم ساخته‌شده');
                $crafted = $this->one('SELECT pci.instance_id, ci.item_name, ci.item_type FROM player_crafted_items pci JOIN craftable_items ci ON ci.craft_item_id = pci.craft_item_id WHERE pci.instance_id = ? AND pci.owner_player_id = ?', [$instanceId, $playerId]);
                if (!$crafted || self::text($crafted['item_type']) !== $slot) throw new GameException('این آیتم ساخته‌شده برای تجهیز در دسترس نیست.');
                $this->execute('INSERT INTO equipment (player_id, ' . $craftedField . ', ' . $field . ') VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE ' . $craftedField . ' = VALUES(' . $craftedField . '), ' . $field . ' = NULL', [$playerId, $instanceId]);
                return self::event('success', 'تجهیزات تغییر کرد', '«' . self::text($crafted['item_name']) . '» تجهیز شد.');
            }
            $itemId = self::assertInt($itemInput, 'شناسه آیتم');
            $item = $this->one('SELECT i.item_name FROM inventory inv JOIN items i ON i.item_id = inv.item_id WHERE inv.player_id = ? AND inv.item_id = ? AND i.type_id = ? AND inv.quantity > 0', [$playerId, $itemId, $slot === 'weapon' ? 1 : 2]);
            if (!$item) throw new GameException('این آیتم برای تجهیز در کوله‌پشتی شما نیست.');
            $this->execute('INSERT INTO equipment (player_id, ' . $field . ', ' . $craftedField . ') VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE ' . $field . ' = VALUES(' . $field . '), ' . $craftedField . ' = NULL', [$playerId, $itemId]);
            return self::event('success', 'تجهیزات تغییر کرد', '«' . self::text($item['item_name']) . '» تجهیز شد.');
        });
    }

    /** @return array<string, mixed> */
    private function unequip(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $this->preparePlayer($playerId, true);
            $this->execute('UPDATE equipment SET weapon_id = NULL, armor_id = NULL, crafted_weapon_instance_id = NULL, crafted_armor_instance_id = NULL WHERE player_id = ?', [$playerId]);
            return self::event('success', 'تجهیزات خارج شد', 'سلاح و زره فعلی شما از حالت تجهیز خارج شد.');
        });
    }

    /** @return array<string, mixed> */
    private function summonPet(int $playerId, $itemInput): array
    {
        $itemId = self::assertInt($itemInput, 'شناسه حیوان');
        return $this->transaction(function () use ($playerId, $itemId): array {
            $this->preparePlayer($playerId, true);
            $pet = $this->one('SELECT i.item_name FROM inventory inv JOIN items i ON i.item_id = inv.item_id WHERE inv.player_id = ? AND inv.item_id = ? AND i.type_id = 4 AND inv.quantity > 0', [$playerId, $itemId]);
            if (!$pet) throw new GameException('این حیوان همراه در موجودی شما نیست.');
            $this->execute('INSERT INTO equipment (player_id, pet_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE pet_id = VALUES(pet_id)', [$playerId, $itemId]);
            return self::event('success', 'حیوان همراه احضار شد', '«' . self::text($pet['item_name']) . '» اکنون همراه شماست.');
        });
    }

    /** @return array<string, mixed> */
    private function restPet(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $this->preparePlayer($playerId, true);
            $this->execute('UPDATE equipment SET pet_id = NULL WHERE player_id = ?', [$playerId]);
            return self::event('success', 'حیوان همراه به استراحت رفت', 'حیوان همراه فعلی شما از حالت احضار خارج شد.');
        });
    }

    /** @return array<string, mixed> */
    private function targetPlayer(int $sourceId, $targetInput): array
    {
        $targetId = self::assertInt($targetInput, 'شناسه بازیکن مقصد');
        if ($targetId === $sourceId) throw new GameException('نمی‌توانید به خودتان انتقال دهید.');
        return $this->readPlayer($targetId, true);
    }

    /** @return array<string, mixed> */
    private function transferCoins(int $playerId, $targetInput, $amountInput, bool $partnerGift): array
    {
        $amount = self::assertInt($amountInput, 'مبلغ');
        return $this->transaction(function () use ($playerId, $targetInput, $amount, $partnerGift): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $target = $this->targetPlayer($playerId, $targetInput);
            $tax = CardinalRules::taxRate($player['class_name'] ?? null, $this->isNoble($player));
            if (($player['class_name'] ?? '') === 'Merchant') $tax /= 2;
            if ($partnerGift) {
                $couple = $this->one('SELECT 1 AS present FROM couples WHERE (player_id_1 = ? AND player_id_2 = ?) OR (player_id_1 = ? AND player_id_2 = ?)', [$playerId, self::integer($target['player_id']), self::integer($target['player_id']), $playerId]);
                if (!$couple) throw new GameException('هدیه بدون مالیات فقط برای پارتنر ثبت‌شده است.');
                $tax = 0;
            }
            $total = (int) floor($amount + $amount * $tax / 100);
            if (self::integer($player['coins']) < $total) throw new GameException('موجودی کافی نیست؛ با مالیات به ' . $total . 'C نیاز دارید.');
            $this->execute('UPDATE players SET coins = coins - ? WHERE player_id = ?', [$total, $playerId]);
            $this->execute('UPDATE players SET coins = coins + ? WHERE player_id = ?', [$amount, self::integer($target['player_id'])]);
            $this->execute("INSERT INTO transactions (player_id, type, amount_coins, status) VALUES (?, 'coin_transfer', ?, 'approved')", [$playerId, $total]);
            $this->queueNotification($target, '🔔 ' . $amount . 'C از ' . self::text($player['player_name']) . ' دریافت کردید.');
            return self::event('success', 'انتقال وجه موفق', $amount . 'C به ' . self::text($target['player_name']) . ' منتقل شد.', ['rewards' => ['coins' => -$total]]);
        });
    }

    /** @return array<string, mixed> */
    private function transferItem(int $playerId, $targetInput, $itemInput, $quantityInput): array
    {
        $itemId = self::assertInt($itemInput, 'شناسه آیتم');
        $quantity = self::assertInt($quantityInput, 'تعداد');
        return $this->transaction(function () use ($playerId, $targetInput, $itemId, $quantity): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $target = $this->targetPlayer($playerId, $targetInput);
            $item = $this->one('SELECT * FROM items WHERE item_id = ?', [$itemId]);
            if (!$item || $itemId === 4) throw new GameException('این کالا قابل انتقال نیست.');
            if ($this->one('SELECT player_id FROM equipment WHERE player_id = ? AND (weapon_id = ? OR armor_id = ? OR pet_id = ?)', [$playerId, $itemId, $itemId, $itemId])) throw new GameException('ابتدا این آیتم را از تجهیزات خارج کنید.');
            if (!$this->hasInventorySpace($target, $quantity)) throw new GameException('کوله‌پشتی بازیکن مقصد ظرفیت کافی ندارد.');
            $tax = CardinalRules::taxRate($player['class_name'] ?? null, $this->isNoble($player));
            $fee = (int) floor(self::num($item['price_coins'] ?? 50, 50) * $quantity * $tax / 100);
            if (self::integer($player['coins']) < $fee) throw new GameException('سکه کافی برای مالیات ' . $fee . 'C ندارید.');
            $this->deductInventory($playerId, $itemId, $quantity);
            $this->addInventory(self::integer($target['player_id']), $itemId, $quantity);
            $this->execute('UPDATE players SET coins = coins - ? WHERE player_id = ?', [$fee, $playerId]);
            $this->queueNotification($target, '🔔 ' . $quantity . ' عدد «' . self::text($item['item_name']) . '» از ' . self::text($player['player_name']) . ' دریافت کردید.');
            return self::event('success', 'انتقال کالا موفق', $quantity . ' عدد «' . self::text($item['item_name']) . '» منتقل شد.', ['rewards' => ['coins' => -$fee]]);
        });
    }

    /** @return array<string, mixed> */
    private function transferMini(int $playerId, $targetInput, $miniInput, $quantityInput): array
    {
        $miniItemId = self::assertInt($miniInput, 'شناسه ماده اولیه');
        $quantity = self::assertInt($quantityInput, 'تعداد');
        return $this->transaction(function () use ($playerId, $targetInput, $miniItemId, $quantity): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $target = $this->targetPlayer($playerId, $targetInput);
            if (!$this->hasInventorySpace($target, $quantity)) throw new GameException('کوله‌پشتی بازیکن مقصد ظرفیت کافی ندارد.');
            $mini = $this->one('SELECT name FROM mini_items WHERE mini_item_id = ?', [$miniItemId]);
            if (!$mini) throw new GameException('ماده اولیه یافت نشد.');
            $this->deductMini($playerId, $miniItemId, $quantity);
            $this->addMiniItem(self::integer($target['player_id']), $miniItemId, $quantity);
            $this->queueNotification($target, '🔔 ' . $quantity . ' عدد ماده «' . self::text($mini['name']) . '» از ' . self::text($player['player_name']) . ' دریافت کردید.');
            return self::event('success', 'انتقال ماده اولیه موفق', $quantity . ' عدد «' . self::text($mini['name']) . '» منتقل شد.');
        });
    }

    /** @return array<string, mixed> */
    private function transferCrafted(int $playerId, $targetInput, $instanceInput): array
    {
        $instanceId = self::assertInt($instanceInput, 'شناسه آیتم ساخته‌شده');
        return $this->transaction(function () use ($playerId, $targetInput, $instanceId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $target = $this->targetPlayer($playerId, $targetInput);
            if (!$this->hasInventorySpace($target)) throw new GameException('کوله‌پشتی بازیکن مقصد ظرفیت کافی ندارد.');
            $crafted = $this->one('SELECT pci.instance_id, ci.item_name FROM player_crafted_items pci JOIN craftable_items ci ON ci.craft_item_id = pci.craft_item_id WHERE pci.instance_id = ? AND pci.owner_player_id = ? AND ci.is_tradeable = TRUE', [$instanceId, $playerId]);
            if (!$crafted) throw new GameException('این آیتم ساخته‌شده قابل انتقال نیست.');
            if ($this->one('SELECT player_id FROM equipment WHERE crafted_weapon_instance_id = ? OR crafted_armor_instance_id = ?', [$instanceId, $instanceId])) throw new GameException('ابتدا این آیتم را از تجهیزات خارج کنید.');
            $this->execute('UPDATE player_crafted_items SET owner_player_id = ? WHERE instance_id = ? AND owner_player_id = ?', [self::integer($target['player_id']), $instanceId, $playerId]);
            $this->queueNotification($target, '🔔 «' . self::text($crafted['item_name']) . '» از ' . self::text($player['player_name']) . ' دریافت کردید.');
            return self::event('success', 'انتقال تجهیزات موفق', '«' . self::text($crafted['item_name']) . '» منتقل شد.');
        });
    }

    /** @return array<string, mixed> */
    private function dropItem(int $playerId, $itemInput, $quantityInput): array
    {
        $itemId = self::assertInt($itemInput, 'شناسه آیتم');
        $quantity = self::assertInt($quantityInput, 'تعداد');
        return $this->transaction(function () use ($playerId, $itemId, $quantity): array {
            // No location guard: the Telegram bots let a player discard from
            // anywhere, and this only removes the player's own rows. It grants
            // nothing and alters no formula.
            $this->preparePlayer($playerId, true);
            if ($itemId === 4) throw new GameException('این آیتم قابل دور انداختن نیست.');
            if ($this->one('SELECT player_id FROM equipment WHERE player_id = ? AND (weapon_id = ? OR armor_id = ? OR pet_id = ?)', [$playerId, $itemId, $itemId, $itemId])) throw new GameException('ابتدا این آیتم را از تجهیزات خارج کنید.');
            $item = $this->one('SELECT item_name FROM items WHERE item_id = ?', [$itemId]);
            if (!$item) throw new GameException('آیتم پیدا نشد.');
            $this->deductInventory($playerId, $itemId, $quantity);
            return self::event('warning', 'آیتم دور انداخته شد', $quantity . ' عدد «' . self::text($item['item_name']) . '» از کوله‌پشتی حذف شد.');
        });
    }

    /** @return array<string, mixed> */
    private function dropMini(int $playerId, $miniInput, $quantityInput): array
    {
        $miniItemId = self::assertInt($miniInput, 'شناسه ماده اولیه');
        $quantity = self::assertInt($quantityInput, 'تعداد');
        return $this->transaction(function () use ($playerId, $miniItemId, $quantity): array {
            $this->preparePlayer($playerId, true);
            $mini = $this->one('SELECT name FROM mini_items WHERE mini_item_id = ?', [$miniItemId]);
            if (!$mini) throw new GameException('ماده اولیه پیدا نشد.');
            $this->deductMini($playerId, $miniItemId, $quantity);
            return self::event('warning', 'ماده اولیه دور انداخته شد', $quantity . ' عدد «' . self::text($mini['name']) . '» حذف شد.');
        });
    }

    /**
     * Discard a crafted instance permanently.
     *
     * The web build could craft and upgrade items but never destroy one, while
     * the bots already could, so a full backpack could not be cleared here.
     * Deletes only the caller's own row and refunds nothing.
     *
     * @return array<string, mixed>
     */
    private function dropCrafted(int $playerId, $instanceInput): array
    {
        $instanceId = self::assertInt($instanceInput, 'شناسه آیتم ساخته‌شده');
        return $this->transaction(function () use ($playerId, $instanceId): array {
            $this->preparePlayer($playerId, true);
            $crafted = $this->one(
                'SELECT pci.instance_id, pci.upgrade_level, ci.item_name
                 FROM player_crafted_items pci
                 JOIN craftable_items ci ON ci.craft_item_id = pci.craft_item_id
                 WHERE pci.instance_id = ? AND pci.owner_player_id = ?',
                [$instanceId, $playerId]
            );
            if (!$crafted) throw new GameException('این آیتم ساخته‌شده در کوله‌پشتی شما نیست.');
            if ($this->one('SELECT player_id FROM equipment WHERE crafted_weapon_instance_id = ? OR crafted_armor_instance_id = ?', [$instanceId, $instanceId])) {
                throw new GameException('ابتدا این آیتم را از تجهیزات خارج کنید.');
            }
            $this->execute('DELETE FROM player_crafted_items WHERE instance_id = ? AND owner_player_id = ?', [$instanceId, $playerId]);
            $label = self::text($crafted['item_name']) . ' (+' . self::integer($crafted['upgrade_level']) . ')';
            return self::event('warning', 'آیتم ساخته‌شده دور انداخته شد', '«' . $label . '» برای همیشه حذف شد.');
        });
    }

    /** @return array<string, mixed> */
    private function acceptDailyQuest(int $playerId, $questInput): array
    {
        $questId = self::assertInt($questInput, 'شناسه مأموریت');
        return $this->transaction(function () use ($playerId, $questId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $this->ensureDailyQuests($player);
            $quest = $this->one("SELECT * FROM player_daily_quests WHERE daily_quest_id = ? AND player_id = ?", [$questId, $playerId]);
            if (!$quest || ($quest['status'] ?? '') !== 'available') throw new GameException('این مأموریت در دسترس نیست.');
            $meta = $this->one('SELECT * FROM player_quest_meta WHERE player_id = ?', [$playerId]);
            if (self::sameDay($meta['activated_date'] ?? null) && self::integer($meta['activated_today'] ?? 0) >= 3) throw new GameException('امروز حداکثر ۳ مأموریت را فعال کرده‌اید.');
            $this->execute("UPDATE player_daily_quests SET status = 'active', activated_at = NOW(), deadline = DATE_ADD(NOW(), INTERVAL 24 HOUR) WHERE daily_quest_id = ?", [$questId]);
            $this->execute('INSERT INTO player_quest_meta (player_id, activated_today, activated_date, last_generated_at) VALUES (?, 1, CURDATE(), NOW()) ON DUPLICATE KEY UPDATE activated_today = activated_today + 1, activated_date = CURDATE()', [$playerId]);
            return self::event('success', 'مأموریت پذیرفته شد', 'مهلت تکمیل این مأموریت ۲۴ ساعت است.');
        });
    }

    /** @return array<string, mixed> */
    private function createParty(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            // The private-menu flow shared by the source bots allows a
            // defensive lobby to be staged from the city. Joining it remains
            // restricted to players outside the city in joinParty().
            $lobbies = $this->getLobbies();
            $this->assertNoActiveParty($playerId, $lobbies);
            $code = $this->nextLobbyCode($lobbies);
            $this->setLobby([
                'lobbyCode' => $code,
                'lobbyType' => 'defensive',
                'floor' => self::integer($player['current_floor']),
                'leaderId' => $playerId,
                'targetPlayerId' => null,
                'members' => [$playerId],
            ]);
            return self::event('success', 'تیم دفاعی تشکیل شد', 'شناسه تیم شما ' . $code . ' است. حداکثر ۷ عضو می‌توانند در آن حاضر باشند.');
        });
    }

    /** @return array<string, mixed> */
    private function createTeamPvp(int $playerId, $targetInput): array
    {
        $targetId = self::assertInt($targetInput, 'شناسه هدف تیمی');
        if ($targetId === $playerId) throw new GameException('شما نمی‌توانید به آواتار خودتان حمله تیمی کنید.');
        return $this->transaction(function () use ($playerId, $targetId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'wild') throw new GameException('برای حمله تیمی ابتدا از شهر خارج شوید.');
            $target = $this->readPlayer($targetId, true);
            if (($target['current_location'] ?? '') === 'city') throw new GameException('بازیکن هدف در منطقه امن شهر است.');
            if (self::integer($target['current_floor']) !== self::integer($player['current_floor'])) {
                throw new GameException('بازیکن هدف در طبقه ' . self::integer($target['current_floor']) . ' است و حمله تیمی فقط میان بازیکنان هم‌طبقه ممکن است.');
            }
            $lobbies = $this->getLobbies();
            $this->assertNoActiveParty($playerId, $lobbies);
            // The bot blocks only a target that is already on an active
            // offensive roster; a defensive alliance remains attackable.
            foreach ($lobbies as $lobby) {
                if ($lobby['lobbyType'] === 'offensive' && in_array($targetId, $lobby['members'], true)) {
                    throw new GameException('بازیکن هدف هم‌اکنون عضو یک تیم مهاجم فعال است و نمی‌توان به او حمله تیمی زد.');
                }
            }
            $code = $this->nextLobbyCode($lobbies);
            $this->setLobby([
                'lobbyCode' => $code,
                'lobbyType' => 'offensive',
                'floor' => self::integer($player['current_floor']),
                'leaderId' => $playerId,
                'targetPlayerId' => $targetId,
                'members' => [$playerId],
            ]);
            return self::event('success', 'لشکرکشی تشکیل شد', 'لابی تهاجمی علیه «' . self::text($target['player_name']) . '» ایجاد شد. شناسه دعوت: ' . $code . ' · ظرفیت ۵ نفر.');
        });
    }

    /** @return array<string, mixed> */
    private function createTeamBoss(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            if (($player['current_location'] ?? '') !== 'wild') throw new GameException('برای فراخوان فتح طبقه ابتدا از شهر خارج شوید.');
            $this->assertNoTeamBossFatigue($player);
            $lobbies = $this->getLobbies();
            $this->assertNoActiveParty($playerId, $lobbies);
            $code = $this->nextLobbyCode($lobbies);
            // This is deliberately an offensive lobby without a target. That
            // is the persisted contract used by all three source bots for a
            // team boss expedition.
            $this->setLobby([
                'lobbyCode' => $code,
                'lobbyType' => 'offensive',
                'floor' => self::integer($player['current_floor']),
                'leaderId' => $playerId,
                'targetPlayerId' => null,
                'members' => [$playerId],
            ]);
            return self::event('success', 'فراخوان فتح طبقه ثبت شد', 'تیم تهاجمی برای باس طبقه ' . self::integer($player['current_floor']) . ' تشکیل شد. شناسه دعوت: ' . $code . ' · ظرفیت ۵ نفر.');
        });
    }

    /** @return array<string, mixed> */
    private function joinParty(int $playerId, $codeInput): array
    {
        $code = (string) self::assertInt($codeInput, 'شناسه تیم', 100000, 999999);
        return $this->transaction(function () use ($playerId, $code): array {
            $player = $this->preparePlayer($playerId, true);
            $lobbies = $this->getLobbies();
            $this->assertNoActiveParty($playerId, $lobbies);
            $lobby = null;
            foreach ($lobbies as $candidate) if ($candidate['lobbyCode'] === $code) { $lobby = $candidate; break; }
            if (!$lobby) throw new GameException('تیمی با این شناسه یافت نشد.');
            if (($lobby['targetPlayerId'] ?? null) === $playerId) throw new GameException('نمی‌توانید به لابی حمله علیه خودتان بپیوندید.');
            // The reference flow applies post-boss fatigue to every lobby
            // without a player target (defensive or boss expedition).
            if (($lobby['targetPlayerId'] ?? null) === null) $this->assertNoTeamBossFatigue($player);
            $max = $lobby['lobbyType'] === 'defensive' ? 7 : 5;
            if (count($lobby['members']) >= $max) throw new GameException('ظرفیت تیم تکمیل است (حداکثر ' . $max . ' نفر).');
            if (self::integer($player['last_floor_unlocked']) < self::integer($lobby['floor'])) throw new GameException('طبقه ' . self::integer($lobby['floor']) . ' برای شما باز نشده است.');
            if (self::integer($player['current_floor']) !== self::integer($lobby['floor']) || ($player['current_location'] ?? '') !== 'wild') throw new GameException('برای پیوستن باید در همان طبقه و بیرون شهر باشید.');
            $lobby['members'][] = $playerId;
            $this->setLobby($lobby);
            return self::event('success', 'عضویت در تیم موفق', 'شما به تیم ' . $code . ' پیوستید.');
        });
    }

    /** @param array<int, array<int, string>> $first
     * @param array<int, array<int, string>> $second
     * @return array<int, array<int, string>> */
    private function combinePartyDrops(array $first, array $second): array
    {
        foreach ($second as $memberId => $items) {
            if (!isset($first[$memberId])) $first[$memberId] = [];
            foreach ($items as $item) $first[$memberId][] = $item;
        }
        return $first;
    }

    /** @param array<int, array<int, string>> $drops
     * @return array<int, string> */
    private function partyDropSummary(array $drops): array
    {
        $summary = [];
        foreach ($drops as $memberId => $items) {
            $summary[] = 'C_ID ' . self::integer($memberId) . ': ' . implode('، ', $items);
        }
        return $summary;
    }

    /** @return array<string, mixed> */
    private function startPartyBattle(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $leader = $this->preparePlayer($playerId, true);
            $lobby = $this->lobbyForPlayer($playerId);
            if (!$lobby) throw new GameException('شما عضو هیچ تیمی نیستید.');
            if (self::integer($lobby['leaderId']) !== $playerId) throw new GameException('فقط رهبر لابی صلاحیت آغاز نبرد را دارد.');
            $members = array_values(array_unique(array_map(static function ($id): int { return self::integer($id); }, $lobby['members'])));
            if (!$members) throw new GameException('این تیم عضو معتبری ندارد.');

            if (($lobby['targetPlayerId'] ?? null) !== null) {
                $targetId = self::integer($lobby['targetPlayerId']);
                $target = $this->one(
                    'SELECT p.*, c.class_name FROM players p LEFT JOIN classes c ON c.class_id = p.class_id
                     WHERE p.player_id = ? AND p.is_deleted = FALSE FOR UPDATE',
                    [$targetId]
                );
                if (!$target) {
                    $this->deleteLobby(self::text($lobby['lobbyCode']));
                    return self::event('warning', 'لشکرکشی لغو شد', 'بازیکن هدف دیگر در بازی یافت نشد.');
                }
                if (($target['current_location'] ?? '') === 'city') {
                    $this->deleteLobby(self::text($lobby['lobbyCode']));
                    return self::event('warning', 'لشکرکشی لغو شد', 'بازیکن هدف به منطقه امن شهر بازگشته است.');
                }
                if (self::integer($target['current_floor']) !== self::integer($lobby['floor'])) {
                    $this->deleteLobby(self::text($lobby['lobbyCode']));
                    return self::event('warning', 'لشکرکشی لغو شد', 'بازیکن هدف دیگر در طبقه این تیم نیست.');
                }

                $metrics = $this->partyMetrics($members);
                $targetPower = $this->battlePower($targetId);
                if ($metrics['power'] >= $targetPower) {
                    $lootCoins = (int) floor(self::num($target['coins'] ?? 0) * 0.30);
                    $share = (int) floor($lootCoins / count($members));
                    $allLoot = [];
                    foreach ($members as $memberId) {
                        $this->execute('UPDATE players SET coins = coins + ?, kills = kills + 1 WHERE player_id = ? AND is_deleted = FALSE', [$share, $memberId]);
                        foreach ($this->lootFromPlayer($memberId, $targetId) as $item) $allLoot[] = $item;
                    }
                    $wasWanted = in_array(self::text($target['pk_status'] ?? ''), ['yellow', 'red', 'black'], true);
                    if ($wasWanted) $this->execute('UPDATE players SET level = GREATEST(1, level - 1), kills = GREATEST(0, kills - 10) WHERE player_id = ?', [$targetId]);
                    $this->execute("UPDATE players SET coins = coins - ?, deaths = deaths + 1, current_floor = 1, current_location = 'city', pk_status = 'white' WHERE player_id = ?", [$lootCoins, $targetId]);
                    $this->notifyPartnerOfAttack($targetId, 'گروه ' . $playerId, $playerId, self::integer($target['current_floor']), $allLoot);
                    $memberList = implode('، ', array_map(static function (int $id): string { return (string) $id; }, $members));
                    $this->queueNotification($target, '🩸 شما در طبقه ' . self::integer($target['current_floor']) . ' توسط تیم «' . self::text($leader['player_name']) . '» شکست خوردید. C_ID اعضای مهاجم: ' . $memberList . ' · سکه از دست‌رفته: ' . $lootCoins . 'C · به طبقه ۱ منتقل شدید.');
                    $this->deleteLobby(self::text($lobby['lobbyCode']));
                    return self::event('success', 'حمله تیمی موفق', 'تیم شما «' . self::text($target['player_name']) . '» را شکست داد. سهم هر عضو: +' . $share . 'C.', ['rewards' => ['coins' => $share, 'items' => $allLoot]]);
                }

                foreach ($members as $memberId) {
                    $member = $this->one('SELECT coins FROM players WHERE player_id = ? AND is_deleted = FALSE FOR UPDATE', [$memberId]);
                    if (!$member) continue;
                    $lost = (int) floor(self::num($member['coins']) * 0.15);
                    $this->execute("UPDATE players SET coins = coins - ?, deaths = deaths + 1, current_floor = 1, current_location = 'city' WHERE player_id = ?", [$lost, $memberId]);
                }
                $this->deleteLobby(self::text($lobby['lobbyCode']));
                return self::event('danger', 'حمله تیمی نافرجام', 'قدرت دفاعی هدف (' . $targetPower . ') از قدرت تیم (' . $metrics['power'] . ') بیشتر بود؛ تیم به طبقه ۱ بازگشت.');
            }

            $boss = $this->one('SELECT * FROM bosses WHERE floor = ?', [self::integer($leader['current_floor'])]);
            if (!$boss) throw new GameException('غول این طبقه یافت نشد.');
            if (self::integer($leader['last_floor_unlocked']) < self::integer($lobby['floor'])) {
                $this->deleteLobby(self::text($lobby['lobbyCode']));
                return self::event('warning', 'تیم منحل شد', 'رهبر تیم هنوز به طبقه ' . self::integer($lobby['floor']) . ' نرسیده است.');
            }

            $metrics = $this->partyMetrics($members);
            $bossPower = self::integer($boss['level']) * 10;
            if ($metrics['power'] >= $bossPower) {
                $rewardCoins = self::integer($boss['reward_coins']) * count($members);
                $rewardPerMember = (int) floor($rewardCoins / count($members));
                foreach ($members as $memberId) {
                    $member = $this->one(
                        'SELECT p.player_id, p.last_floor_unlocked, p.current_floor, c.class_name
                         FROM players p LEFT JOIN classes c ON c.class_id = p.class_id
                         WHERE p.player_id = ? AND p.is_deleted = FALSE FOR UPDATE',
                        [$memberId]
                    );
                    if (!$member) continue;
                    $memberCoins = self::integer(CardinalRules::merchantCoinReward(self::text($member['class_name']), $rewardPerMember));
                    if (self::integer($member['last_floor_unlocked']) === self::integer($lobby['floor'])) {
                        $this->execute('UPDATE players SET current_floor = current_floor + 1, last_floor_unlocked = last_floor_unlocked + 1, coins = coins + ? WHERE player_id = ?', [$memberCoins, $memberId]);
                    } else {
                        $this->execute('UPDATE players SET coins = coins + ? WHERE player_id = ?', [$memberCoins, $memberId]);
                    }
                    $this->advanceDailyQuest($memberId, 'boss', self::text($boss['boss_name']));
                }
                $bossId = self::integer($boss['boss_id'] ?? ($boss['id'] ?? $boss['floor']));
                $drops = $this->rollTeamMiniDrops('boss', $bossId, $members);
                $drops = $this->combinePartyDrops($drops, $this->rollTeamBossRegularDrops($members));
                $summary = $this->partyDropSummary($drops);
                $leaderCoins = self::integer(CardinalRules::merchantCoinReward(self::text($leader['class_name']), $rewardPerMember));
                $this->applyTeamBossCooldown($members, self::integer($lobby['floor']));
                $this->deleteLobby(self::text($lobby['lobbyCode']));
                return self::event('success', 'فتح گروهی طبقه ' . self::integer($lobby['floor']), 'غول «' . self::text($boss['boss_name']) . '» به زانو درآمد. اعضایی که در مرز این طبقه بودند به طبقه بعدی صعود کردند.', ['rewards' => ['coins' => $leaderCoins, 'miniItems' => $summary]]);
            }

            foreach ($members as $memberId) {
                $member = $this->one('SELECT coins FROM players WHERE player_id = ? AND is_deleted = FALSE FOR UPDATE', [$memberId]);
                if (!$member) continue;
                $lost = (int) floor(self::num($member['coins']) * 0.20);
                $this->execute("UPDATE players SET coins = coins - ?, deaths = deaths + 1, current_floor = 1, current_location = 'city' WHERE player_id = ?", [$lost, $memberId]);
            }
            $this->applyTeamBossCooldown($members, self::integer($lobby['floor']));
            $this->deleteLobby(self::text($lobby['lobbyCode']));
            return self::event('danger', 'شکست در تالار نهایی', 'قدرت غول (' . $bossPower . ') از قدرت تیم (' . $metrics['power'] . ') بیشتر بود؛ اعضا به طبقه ۱ بازگشتند.');
        });
    }

    /** @param array<int, int> $members */
    private function applyTeamBossCooldown(array $members, int $floor): void
    {
        $until = self::mysqlDate(self::now()->modify('+' . max(0, $floor * 10) . ' minutes'));
        foreach ($members as $memberId) {
            $this->execute('UPDATE players SET team_boss_cooldown_until = ? WHERE player_id = ? AND is_deleted = FALSE', [$until, self::integer($memberId)]);
        }
    }

    /** @return array<string, mixed> */
    private function leaveParty(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $this->preparePlayer($playerId, true);
            $lobby = $this->lobbyForPlayer($playerId);
            if (!$lobby) throw new GameException('شما عضو هیچ تیمی نیستید.');
            if (self::integer($lobby['leaderId']) === $playerId) throw new GameException('رهبر تیم ابتدا باید تیم را منحل کند.');
            $lobby['members'] = array_values(array_filter($lobby['members'], function ($id) use ($playerId): bool { return self::integer($id) !== $playerId; }));
            if ($lobby['members']) $this->setLobby($lobby); else $this->deleteLobby(self::text($lobby['lobbyCode']));
            return self::event('success', 'خروج از تیم', 'با موفقیت از تیم خارج شدید.');
        });
    }

    /** @return array<string, mixed> */
    private function disbandParty(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $lobby = $this->lobbyForPlayer($playerId);
            if (!$lobby || self::integer($lobby['leaderId']) !== $playerId) throw new GameException('فقط رهبر تیم می‌تواند آن را منحل کند.');
            $this->deleteLobby(self::text($lobby['lobbyCode']));
            foreach ($lobby['members'] as $memberId) {
                if (self::integer($memberId) === $playerId) continue;
                $member = $this->one('SELECT player_id, server_id FROM players WHERE player_id = ? AND is_deleted = FALSE', [self::integer($memberId)]);
                if ($member) $this->queueNotification($member, '⚠️ ' . self::text($player['player_name']) . ' تیم ' . self::text($lobby['lobbyCode']) . ' را منحل کرد.');
            }
            return self::event('warning', 'تیم منحل شد', 'تمام اعضای تیم به حالت انفرادی بازگشتند.');
        });
    }

    /** @return array<string, mixed>|null */
    private function coupleFor(int $playerId): ?array
    {
        return $this->one('SELECT * FROM couples WHERE player_id_1 = ? OR player_id_2 = ?', [$playerId, $playerId]);
    }

    /** @return array<string, mixed> */
    private function invitePartner(int $playerId, $targetInput): array
    {
        return $this->transaction(function () use ($playerId, $targetInput): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $target = $this->targetPlayer($playerId, $targetInput);
            if ($this->coupleFor($playerId) || $this->coupleFor(self::integer($target['player_id']))) throw new GameException('شما یا بازیکن هدف هم‌اکنون پیوند فعالی دارید.');
            if (empty($target['chat_id_pv']) && self::integer($target['server_id']) !== self::WEB_SERVER_ID) throw new GameException('بازیکن هدف هنوز در گفت‌وگوی خصوصی یکی از نسخه‌های بازی فعال نشده است.');
            $this->queueNotification($target, '💍 درخواست پیوند از ' . self::text($player['player_name']) . ' (شناسه ' . $playerId . ') دریافت شد.');
            return self::event('info', 'درخواست پیوند ارسال شد', 'در انتظار پذیرش پارتنر بمانید.', ['refresh' => false]);
        });
    }

    /** @return array<string, mixed> */
    private function acceptPartner(int $playerId, $partnerInput): array
    {
        return $this->transaction(function () use ($playerId, $partnerInput): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $partner = $this->targetPlayer($playerId, $partnerInput);
            if ($this->coupleFor($playerId) || $this->coupleFor(self::integer($partner['player_id']))) throw new GameException('یکی از دو بازیکن پیش‌تر پارتنر دارد.');
            $this->execute('INSERT INTO couples (player_id_1, player_id_2) VALUES (?, ?)', [self::integer($partner['player_id']), $playerId]);
            $this->queueNotification($partner, '🎉 ' . self::text($player['player_name']) . ' درخواست پیوند شما را پذیرفت.');
            return self::event('success', 'پیوند ثبت شد', 'پیوند شما با ' . self::text($partner['player_name']) . ' در تالار کاردینال ثبت شد.');
        });
    }

    /** @return array<string, mixed> */
    private function removePartner(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if (!$this->coupleFor($playerId)) throw new GameException('شما پارتنری ندارید.');
            $this->execute('DELETE FROM couples WHERE player_id_1 = ? OR player_id_2 = ?', [$playerId, $playerId]);
            return self::event('warning', 'پیوند گسست', 'پیوند شما در تالار کاردینال منحل شد.');
        });
    }

    /** @return array<string, mixed>|null */
    private function membership(int $playerId, bool $lock = false): ?array
    {
        return $this->one("SELECT g.*, gm.rank, gm.status FROM guild_members gm JOIN guilds g ON g.guild_id = gm.guild_id WHERE gm.player_id = ? AND gm.status = 'accepted' " . ($lock ? 'FOR UPDATE' : ''), [$playerId]);
    }

    private function canManageGuild($rank): bool
    {
        return in_array(strtolower(trim(self::text($rank))), ['leader', 'officer'], true);
    }

    private function isGuildLeader($rank): bool
    {
        return strtolower(trim(self::text($rank))) === 'leader';
    }

    /** @return array<string, mixed> */
    private function createGuild(int $playerId, $rawName): array
    {
        $guildName = self::cleanName(self::text($rawName));
        if (mb_strlen($guildName, 'UTF-8') < 3 || mb_strlen($guildName, 'UTF-8') > 15) throw new GameException('نام گیلد باید بین ۳ تا ۱۵ کاراکتر باشد.');
        return $this->transaction(function () use ($playerId, $guildName): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if ($this->one("SELECT player_id FROM guild_members WHERE player_id = ? AND status IN ('accepted', 'pending')", [$playerId])) throw new GameException('شما عضو گیلد هستید یا درخواست عضویت در انتظار دارید.');
            if ($this->one('SELECT guild_id FROM guilds WHERE guild_name = ?', [$guildName])) throw new GameException('این نام پیش‌تر برای یک گیلد رزرو شده است.');
            $cost = 50000;
            if (self::integer($player['coins']) < $cost) throw new GameException('برای تأسیس گیلد به ' . $cost . 'C نیاز دارید.');
            $this->execute('UPDATE players SET coins = coins - ? WHERE player_id = ?', [$cost, $playerId]);
            $result = $this->execute('INSERT INTO guilds (guild_name, leader_id, floor) VALUES (?, ?, ?)', [$guildName, $playerId, self::integer($player['current_floor'])]);
            $this->execute("INSERT INTO guild_members (player_id, guild_id, rank, status) VALUES (?, ?, 'Leader', 'accepted')", [$playerId, $result['insertId']]);
            return self::event('success', 'گیلد تأسیس شد', 'انجمن «' . $guildName . '» با شناسه ' . $result['insertId'] . ' ثبت شد.', ['rewards' => ['coins' => -$cost]]);
        });
    }

    /** @return array<string, mixed> */
    private function joinGuild(int $playerId, $guildInput): array
    {
        $guildId = self::assertInt($guildInput, 'شناسه گیلد');
        return $this->transaction(function () use ($playerId, $guildId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if ($this->one("SELECT player_id FROM guild_members WHERE player_id = ? AND status IN ('accepted', 'pending')", [$playerId])) throw new GameException('شما عضو گیلد هستید یا درخواست عضویت در انتظار دارید.');
            $guild = $this->one('SELECT * FROM guilds WHERE guild_id = ?', [$guildId]);
            if (!$guild) throw new GameException('گیلد مورد نظر پیدا نشد.');
            $countRow = $this->one("SELECT COUNT(*) AS total FROM guild_members WHERE guild_id = ? AND status = 'accepted'", [$guildId]);
            if (self::integer($countRow['total'] ?? 0) >= 20) throw new GameException('ظرفیت این گیلد تکمیل است.');
            $this->execute("INSERT INTO guild_members (player_id, guild_id, rank, status) VALUES (?, ?, 'member', 'pending') ON DUPLICATE KEY UPDATE status = 'pending', rank = 'member', requested_at = NOW()", [$playerId, $guildId]);
            $leader = $this->readPlayer(self::integer($guild['leader_id']));
            $this->queueNotification($leader, '📩 ' . self::text($player['player_name']) . ' درخواست عضویت در گیلد «' . self::text($guild['guild_name']) . '» را ثبت کرد.');
            return self::event('info', 'درخواست عضویت ثبت شد', 'درخواست شما برای «' . self::text($guild['guild_name']) . '» در انتظار تأیید است.');
        });
    }

    /** @return array<string, mixed> */
    private function guildRequest(int $playerId, $targetInput, bool $approve): array
    {
        $targetId = self::assertInt($targetInput, 'شناسه بازیکن');
        return $this->transaction(function () use ($playerId, $targetId, $approve): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $member = $this->membership($playerId, true);
            if (!$member || !$this->canManageGuild($member['rank'] ?? null)) throw new GameException('دسترسی مدیریت گیلد ندارید.', 403);
            $target = $this->readPlayer($targetId, true);
            $request = $this->one("SELECT player_id FROM guild_members WHERE guild_id = ? AND player_id = ? AND status = 'pending'", [self::integer($member['guild_id']), $targetId]);
            if (!$request) throw new GameException('درخواست عضویت معتبری پیدا نشد.');
            if ($approve) {
                $countRow = $this->one("SELECT COUNT(*) AS total FROM guild_members WHERE guild_id = ? AND status = 'accepted'", [self::integer($member['guild_id'])]);
                if (self::integer($countRow['total'] ?? 0) >= 20) throw new GameException('ظرفیت گیلد تکمیل است.');
                $this->execute("UPDATE guild_members SET status = 'accepted', responded_at = NOW() WHERE guild_id = ? AND player_id = ?", [self::integer($member['guild_id']), $targetId]);
                $this->queueNotification($target, '🎉 درخواست عضویت شما در گیلد تأیید شد.');
                return self::event('success', 'درخواست تأیید شد', self::text($target['player_name']) . ' به گیلد پیوست.');
            }
            $this->execute("UPDATE guild_members SET status = 'rejected', responded_at = NOW() WHERE guild_id = ? AND player_id = ?", [self::integer($member['guild_id']), $targetId]);
            $this->queueNotification($target, '❌ درخواست عضویت شما در گیلد رد شد.');
            return self::event('warning', 'درخواست رد شد', 'درخواست ' . self::text($target['player_name']) . ' رد شد.');
        });
    }

    /** @return array<string, mixed> */
    private function guildKick(int $playerId, $targetInput): array
    {
        $targetId = self::assertInt($targetInput, 'شناسه بازیکن');
        return $this->transaction(function () use ($playerId, $targetId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $member = $this->membership($playerId, true);
            if (!$member || !$this->canManageGuild($member['rank'] ?? null)) throw new GameException('دسترسی مدیریت گیلد ندارید.', 403);
            $targetMembership = $this->one("SELECT rank FROM guild_members WHERE guild_id = ? AND player_id = ? AND status = ?", [self::integer($member['guild_id']), $targetId, 'accepted']);
            if (!$targetMembership || $this->isGuildLeader($targetMembership['rank'] ?? null)) throw new GameException('این عضو قابل اخراج نیست.');
            $this->execute('DELETE FROM guild_members WHERE guild_id = ? AND player_id = ?', [self::integer($member['guild_id']), $targetId]);
            return self::event('success', 'عضو اخراج شد', 'بازیکن ' . $targetId . ' از گیلد خارج شد.');
        });
    }

    /** @return array<string, mixed> */
    private function guildRank(int $playerId, $targetInput, $rankInput): array
    {
        $targetId = self::assertInt($targetInput, 'شناسه بازیکن');
        $rank = strtolower(self::text($rankInput));
        if ($rank !== 'officer' && $rank !== 'member') throw new GameException('رتبه انتخاب‌شده نامعتبر است.');
        return $this->transaction(function () use ($playerId, $targetId, $rank): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $member = $this->membership($playerId, true);
            if (!$member || !$this->isGuildLeader($member['rank'] ?? null)) throw new GameException('فقط رهبر گیلد می‌تواند رتبه‌ها را تغییر دهد.', 403);
            $target = $this->one("SELECT rank FROM guild_members WHERE guild_id = ? AND player_id = ? AND status = ?", [self::integer($member['guild_id']), $targetId, 'accepted']);
            if (!$target || $this->isGuildLeader($target['rank'] ?? null)) throw new GameException('این عضو برای تغییر رتبه در دسترس نیست.');
            $this->execute('UPDATE guild_members SET rank = ? WHERE guild_id = ? AND player_id = ?', [$rank, self::integer($member['guild_id']), $targetId]);
            return self::event('success', 'رتبه عضو تغییر کرد', 'رتبه بازیکن ' . $targetId . ' به ' . ($rank === 'officer' ? 'پرچمدار' : 'عضو') . ' تغییر کرد.');
        });
    }

    /** @return array<string, mixed> */
    private function guildSlogan(int $playerId, $sloganInput): array
    {
        $slogan = self::cleanName(self::text($sloganInput));
        if ($slogan === '' || mb_strlen($slogan, 'UTF-8') > 250) throw new GameException('شعار گیلد نامعتبر است.');
        return $this->transaction(function () use ($playerId, $slogan): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $member = $this->membership($playerId, true);
            if (!$member || !$this->canManageGuild($member['rank'] ?? null)) throw new GameException('دسترسی تغییر شعار ندارید.', 403);
            $this->execute('UPDATE guilds SET slogan = ? WHERE guild_id = ?', [$slogan, self::integer($member['guild_id'])]);
            return self::event('success', 'شعار گیلد تغییر کرد', 'شعار جدید با موفقیت ثبت شد.');
        });
    }

    /** @return array<string, mixed> */
    private function guildTransfer(int $playerId, $targetInput): array
    {
        $targetId = self::assertInt($targetInput, 'شناسه بازیکن');
        return $this->transaction(function () use ($playerId, $targetId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $member = $this->membership($playerId, true);
            if (!$member || !$this->isGuildLeader($member['rank'] ?? null)) throw new GameException('فقط رهبر گیلد می‌تواند مالکیت را منتقل کند.', 403);
            $target = $this->one("SELECT player_id FROM guild_members WHERE guild_id = ? AND player_id = ? AND status = 'accepted'", [self::integer($member['guild_id']), $targetId]);
            if (!$target) throw new GameException('بازیکن مقصد عضو پذیرفته‌شده گیلد نیست.');
            $this->execute("UPDATE guild_members SET rank = 'member' WHERE guild_id = ? AND player_id = ?", [self::integer($member['guild_id']), $playerId]);
            $this->execute("UPDATE guild_members SET rank = 'Leader' WHERE guild_id = ? AND player_id = ?", [self::integer($member['guild_id']), $targetId]);
            $this->execute('UPDATE guilds SET leader_id = ? WHERE guild_id = ?', [$targetId, self::integer($member['guild_id'])]);
            return self::event('success', 'مالکیت گیلد منتقل شد', 'بازیکن ' . $targetId . ' اکنون رهبر گیلد است.');
        });
    }

    /** @return array<string, mixed> */
    private function guildDisband(int $playerId, $confirmation): array
    {
        if (self::text($confirmation) !== 'انحلال انجمن') throw new GameException('برای انحلال باید دقیقاً عبارت «انحلال انجمن» را وارد کنید.');
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $member = $this->membership($playerId, true);
            if (!$member || !$this->isGuildLeader($member['rank'] ?? null)) throw new GameException('فقط رهبر گیلد می‌تواند گیلد را منحل کند.', 403);
            $this->execute('DELETE FROM guild_members WHERE guild_id = ?', [self::integer($member['guild_id'])]);
            $this->execute('DELETE FROM guilds WHERE guild_id = ?', [self::integer($member['guild_id'])]);
            return self::event('warning', 'گیلد منحل شد', 'تمام اعضا از گیلد خارج شدند.');
        });
    }

    /** @return array<string, mixed> */
    private function guildLeave(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $member = $this->membership($playerId, true);
            if (!$member) throw new GameException('شما عضو هیچ گیلدی نیستید.');
            if ($this->isGuildLeader($member['rank'] ?? null)) throw new GameException('رهبر گیلد ابتدا باید مالکیت را منتقل یا گیلد را منحل کند.');
            $this->execute('DELETE FROM guild_members WHERE guild_id = ? AND player_id = ?', [self::integer($member['guild_id']), $playerId]);
            return self::event('success', 'خروج از گیلد', 'با موفقیت از گیلد خارج شدید.');
        });
    }

    /** @param array<string, float|int> $materials */
    private function checkAndDeductMaterials(int $playerId, array $materials): void
    {
        foreach ($materials as $rawId => $rawQuantity) {
            $id = self::assertInt($rawId, 'شناسه ماده اولیه');
            $quantity = self::assertInt($rawQuantity, 'تعداد ماده اولیه');
            $row = $this->one('SELECT quantity FROM mini_item_inventory WHERE player_id = ? AND mini_item_id = ?', [$playerId, $id]);
            if (self::integer($row['quantity'] ?? 0) < $quantity) throw new GameException('مواد اولیه کافی برای این کار در اختیار ندارید.');
        }
        foreach ($materials as $rawId => $rawQuantity) $this->deductMini($playerId, self::assertInt($rawId, 'شناسه ماده اولیه'), self::assertInt($rawQuantity, 'تعداد ماده اولیه'));
    }

    /** @param array<string, mixed>
     * @return array<string, mixed> */
    private function upgradeInfo(array $craft, int $level): array
    {
        $nextLevel = $level + 1;
        $power = (int) floor(self::num($craft['base_power'] ?? 0) * pow(1.5, $nextLevel));
        $minutes = self::num($craft['upgrade_time_minutes'] ?? 0) * pow(2, $nextLevel);
        $sellValue = self::num($craft['base_price'] ?? 0) * pow(2, $nextLevel);
        $base = self::jsonRecord($craft['upgrade_materials'] ?? null);
        $materials = [];
        foreach ($base as $id => $quantity) $materials[$id] = $quantity * pow(2, $nextLevel);
        return ['power' => $power, 'minutes' => $minutes, 'sellValue' => $sellValue, 'materials' => $materials];
    }

    /** @return array<string, mixed> */
    private function isBlacksmith(int $playerId): array
    {
        $player = $this->preparePlayer($playerId, true);
        if (self::integer($player['class_id']) !== 3) throw new GameException('فقط بازیکنان کلاس آهنگر به کارگاه دسترسی دارند.', 403);
        return $player;
    }

    /** @return array<int, array<string, mixed>> */
    private function pendingCrafts(int $playerId): array
    {
        return $this->rows("SELECT cq.*, ci.item_name, ci.item_type, ci.base_power, ci.upgrade_time_minutes, ci.base_price FROM crafting_queue cq JOIN craftable_items ci ON ci.craft_item_id = cq.craft_item_id WHERE cq.player_id = ? AND cq.status = 'in_progress' ORDER BY cq.finishes_at", [$playerId]);
    }

    /** @return array<int, string> */
    private function collectReadyCrafts(int $playerId): array
    {
        $queues = $this->pendingCrafts($playerId);
        $collected = [];
        foreach ($queues as $queue) {
            $finishing = self::dateValue($queue['finishes_at'] ?? null);
            if (!$finishing || $finishing > self::now()) continue;
            if (($queue['action_type'] ?? '') === 'craft') {
                $this->execute('INSERT INTO player_crafted_items (craft_item_id, owner_player_id, current_power, upgrade_level) VALUES (?, ?, ?, 0)', [self::integer($queue['craft_item_id']), $playerId, self::num($queue['base_power'])]);
            } else {
                $instance = $this->one('SELECT upgrade_level FROM player_crafted_items WHERE instance_id = ?', [self::integer($queue['target_instance_id'])]);
                if ($instance) {
                    $craft = $this->one('SELECT * FROM craftable_items WHERE craft_item_id = ?', [self::integer($queue['craft_item_id'])]);
                    if ($craft) {
                        $next = $this->upgradeInfo($craft, self::integer($instance['upgrade_level']));
                        $this->execute('UPDATE player_crafted_items SET current_power = ?, upgrade_level = upgrade_level + 1 WHERE instance_id = ?', [$next['power'], self::integer($queue['target_instance_id'])]);
                    }
                }
            }
            $this->execute("UPDATE crafting_queue SET status = 'collected' WHERE queue_id = ?", [self::integer($queue['queue_id'])]);
            $collected[] = self::text($queue['item_name']) . ' (' . (($queue['action_type'] ?? '') === 'craft' ? 'ساخت' : 'ارتقا') . ')';
        }
        return $collected;
    }

    /** @return array<string, mixed> */
    public function getCrafting(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->isBlacksmith($playerId);
            $this->requireCity($player);
            $collected = $this->collectReadyCrafts($playerId);
            $plans = $this->rows('SELECT * FROM craftable_items WHERE floor = ? ORDER BY item_name', [self::integer($player['current_floor'])]);
            $queues = $this->pendingCrafts($playerId);
            $crafted = $this->rows('SELECT pci.*, ci.item_name, ci.item_type, ci.is_upgradeable, ci.is_tradeable, ci.base_power, ci.base_price, ci.upgrade_materials, ci.upgrade_time_minutes FROM player_crafted_items pci JOIN craftable_items ci ON ci.craft_item_id = pci.craft_item_id WHERE pci.owner_player_id = ? ORDER BY pci.created_at DESC', [$playerId]);
            return [
                'collected' => $collected,
                'plans' => array_map(function (array $plan): array { return ['id' => self::integer($plan['craft_item_id']), 'name' => self::text($plan['item_name']), 'type' => self::text($plan['item_type']), 'floor' => self::integer($plan['floor']), 'power' => self::integer($plan['base_power']), 'minutes' => self::integer($plan['craft_time_minutes']), 'materials' => self::jsonRecord($plan['craft_materials'] ?? null)]; }, $plans),
                'queues' => array_map(function (array $queue): array { return ['id' => self::integer($queue['queue_id']), 'name' => self::text($queue['item_name']), 'type' => self::text($queue['action_type']), 'finishesAt' => self::iso($queue['finishes_at'] ?? null)]; }, $queues),
                'crafted' => array_map(function (array $item): array { return ['id' => self::integer($item['instance_id']), 'craftItemId' => self::integer($item['craft_item_id']), 'name' => self::text($item['item_name']), 'type' => self::text($item['item_type']), 'power' => self::integer($item['current_power']), 'level' => self::integer($item['upgrade_level']), 'upgradeable' => self::boolValue($item['is_upgradeable']), 'next' => $this->upgradeInfo($item, self::integer($item['upgrade_level']))]; }, $crafted),
            ];
        });
    }

    /** @return array<string, mixed> */
    private function craft(int $playerId, $craftInput): array
    {
        $craftItemId = self::assertInt($craftInput, 'شناسه نقشه ساخت');
        return $this->transaction(function () use ($playerId, $craftItemId): array {
            $player = $this->isBlacksmith($playerId);
            $this->requireCity($player);
            $craft = $this->one('SELECT * FROM craftable_items WHERE craft_item_id = ? AND floor = ?', [$craftItemId, self::integer($player['current_floor'])]);
            if (!$craft) throw new GameException('این نقشه ساخت در طبقه فعلی شما موجود نیست.');
            if (count($this->pendingCrafts($playerId)) >= 5) throw new GameException('ظرفیت کارگاه پر است؛ حداکثر ۵ پروژه هم‌زمان مجاز است.');
            if (!$this->hasInventorySpace($player)) throw new GameException('ظرفیت کوله‌پشتی شما پر است.');
            $this->checkAndDeductMaterials($playerId, self::jsonRecord($craft['craft_materials'] ?? null));
            $craftSeconds = (int) floor(self::num($craft['craft_time_minutes']) * 60);
            $finishesAt = self::mysqlDate(self::now()->modify('+' . $craftSeconds . ' seconds'));
            $this->execute("INSERT INTO crafting_queue (player_id, craft_item_id, action_type, finishes_at) VALUES (?, ?, 'craft', ?)", [$playerId, $craftItemId, $finishesAt]);
            return self::event('success', 'ساخت آغاز شد', '«' . self::text($craft['item_name']) . '» در کارگاه قرار گرفت و در ' . self::integer($craft['craft_time_minutes']) . ' دقیقه آماده می‌شود.');
        });
    }

    /** @return array<string, mixed> */
    private function upgrade(int $playerId, $instanceInput): array
    {
        $instanceId = self::assertInt($instanceInput, 'شناسه آیتم ساخته‌شده');
        return $this->transaction(function () use ($playerId, $instanceId): array {
            $player = $this->isBlacksmith($playerId);
            $this->requireCity($player);
            $instance = $this->one('SELECT pci.*, ci.* FROM player_crafted_items pci JOIN craftable_items ci ON ci.craft_item_id = pci.craft_item_id WHERE pci.instance_id = ? AND pci.owner_player_id = ? AND ci.is_upgradeable = TRUE', [$instanceId, $playerId]);
            if (!$instance) throw new GameException('این آیتم برای ارتقا در دسترس نیست.');
            if ($this->one("SELECT queue_id FROM crafting_queue WHERE target_instance_id = ? AND action_type = 'upgrade' AND status = 'in_progress'", [$instanceId])) throw new GameException('این آیتم هم‌اکنون در صف ارتقا است.');
            if (count($this->pendingCrafts($playerId)) >= 5) throw new GameException('ظرفیت کارگاه پر است.');
            $maxUpgrade = (int) floor(self::integer($player['level']) / 10) + 1;
            if (self::integer($instance['upgrade_level']) >= $maxUpgrade) throw new GameException('در سطح فعلی شما، سقف ارتقای این آیتم ' . $maxUpgrade . ' است.');
            $info = $this->upgradeInfo($instance, self::integer($instance['upgrade_level']));
            $this->checkAndDeductMaterials($playerId, $info['materials']);
            $upgradeSeconds = (int) floor(self::num($info['minutes']) * 60);
            $finishesAt = self::mysqlDate(self::now()->modify('+' . $upgradeSeconds . ' seconds'));
            $this->execute("INSERT INTO crafting_queue (player_id, craft_item_id, action_type, target_instance_id, finishes_at) VALUES (?, ?, 'upgrade', ?, ?)", [$playerId, self::integer($instance['craft_item_id']), $instanceId, $finishesAt]);
            return self::event('success', 'ارتقا آغاز شد', 'ارتقای «' . self::text($instance['item_name']) . '» تا سطح ' . (self::integer($instance['upgrade_level']) + 1) . ' در ' . self::num($info['minutes']) . ' دقیقه آماده می‌شود.');
        });
    }

    /** @return array<string, mixed> */
    private function collectCrafting(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->isBlacksmith($playerId);
            $this->requireCity($player);
            $collected = $this->collectReadyCrafts($playerId);
            if (!$collected) return self::event('info', 'کارگاه', 'پروژه آماده‌ای برای دریافت وجود ندارد.', ['refresh' => false]);
            return self::event('success', 'پروژه‌ها آماده شدند', implode('، ', $collected));
        });
    }

    /** @return array<string, mixed> */
    public function getNoble(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            $active = $this->one('SELECT * FROM season_cards WHERE is_active = TRUE ORDER BY season_id DESC LIMIT 1');
            $owned = $this->rows('SELECT sc.season_number, sc.item_name FROM player_season_cards psc JOIN season_cards sc ON sc.season_id = psc.season_id WHERE psc.player_id = ? ORDER BY sc.season_number DESC', [$playerId]);
            return [
                'active' => $this->isNoble($player), 'expiry' => self::iso($player['noble_expiry_date'] ?? null),
                'owned' => array_map(function (array $season): array { return ['number' => self::integer($season['season_number']), 'name' => self::text($season['item_name'])]; }, $owned),
                'offer' => $active ? ['id' => self::integer($active['season_id']), 'number' => self::integer($active['season_number']), 'name' => self::text($active['item_name']), 'description' => self::text($active['description'] ?? ''), 'price' => self::num($active['price_real']), 'validUntil' => self::iso($active['valid_until'] ?? null)] : null,
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function getAchievements(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            // Rubika exposes seasonal cards as the player's achievements. This
            // is intentionally a read of the existing card tables only.
            $this->readPlayer($playerId);
            $rows = $this->rows(
                'SELECT sc.season_number, sc.item_name, sc.description
                 FROM player_season_cards psc
                 JOIN season_cards sc ON psc.season_id = sc.season_id
                 WHERE psc.player_id = ?
                 ORDER BY sc.season_number DESC',
                [$playerId]
            );
            return array_map(function (array $row): array {
                return [
                    'number' => self::integer($row['season_number']),
                    'name' => self::text($row['item_name']),
                    'description' => self::text($row['description'] ?? ''),
                ];
            }, $rows);
        });
    }

    /** @return array<string, mixed> */
    private function createBagInvoice(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if (self::integer($player['bag_level']) >= 10) throw new GameException('کوله‌پشتی شما به سطح نهایی رسیده است.');
            $cost = self::integer($player['bag_level']) < 5 ? 15000 : 30000;
            $result = $this->execute('INSERT INTO pending_payments (player_id, item_name, amount) VALUES (?, ?, ?)', [$playerId, 'ارتقای کوله‌پشتی به لول ' . (self::integer($player['bag_level']) + 1), $cost]);
            return self::event('info', 'فاکتور ارتقای کوله‌پشتی', 'فاکتور ' . $result['insertId'] . ' برای ارتقای کوله‌پشتی ثبت شد. همان روال تأیید دستی فعلی اعمال می‌شود.', ['refresh' => false]);
        });
    }

    /** @return array<string, mixed> */
    private function createNobleInvoice(int $playerId): array
    {
        return $this->transaction(function () use ($playerId): array {
            $player = $this->preparePlayer($playerId, true);
            $this->requireCity($player);
            if ($this->isNoble($player)) throw new GameException('نشان اشراف شما هم‌اکنون فعال است.');
            $season = $this->one('SELECT * FROM season_cards WHERE is_active = TRUE ORDER BY season_id DESC LIMIT 1');
            if (!$season) throw new GameException('در حال حاضر فصل فعالی برای نشان اشراف وجود ندارد.');
            $result = $this->execute('INSERT INTO pending_payments (player_id, item_name, amount, season_id) VALUES (?, ?, ?, ?)', [$playerId, self::text($season['item_name']), self::num($season['price_real']), self::integer($season['season_id'])]);
            return self::event('info', 'فاکتور نشان اشراف', 'فاکتور ' . $result['insertId'] . ' برای «' . self::text($season['item_name']) . '» ثبت شد. همان روال تأیید دستی فعلی اعمال می‌شود.', ['refresh' => false]);
        });
    }
}
