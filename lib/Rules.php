<?php
declare(strict_types=1);

/**
 * Calibrations copied from the existing Cardinal bot rules. No database state
 * is created or migrated here.
 */
final class CardinalRules
{
    private const LEVEL_POWER_TABLE = [
        [5, 2000],
        [10, 2200],
        [15, 2420],
        [20, 2660],
        [25, 2930],
        [30, 3220],
        [35, 3545],
        [40, 3895],
        [45, 4285],
        [50, 4715],
        [55, 5185],
        [60, 5705],
        [65, 6275],
        [70, 6905],
        [75, 7595],
        [80, 8355],
        [85, 9190],
        [90, 10110],
        [95, 11120],
        [100, 12230],
        [105, 13455],
        [110, 14800],
        [115, 16280],
        [120, 17910],
        [125, 19700],
        [130, 21670],
        [135, 23835],
        [140, 26220],
        [145, 28840],
        [150, 31725],
        [155, 34900],
        [160, 38390],
        [165, 42230],
        [170, 46450],
        [175, 51095],
        [180, 56205],
        [185, 61825],
        [190, 68010],
        [195, 74810],
        [200, 82290],
        [205, 90520],
        [210, 99570],
        [215, 109525],
        [220, 120480],
        [225, 132530],
        [230, 145780],
        [235, 160360],
        [240, 176395],
        [245, 194035],
        [250, 1067200],
        [255, 1173900],
        [260, 1291300],
        [265, 1420425],
        [270, 1562475],
        [275, 1718725],
        [280, 1890600],
        [285, 2079650],
        [290, 2287625],
        [295, 2516375],
        [300, 3875235],
        [305, 4262755],
        [310, 4689020],
        [315, 5157915],
        [320, 5673710],
        [325, 6241095],
        [330, 6865180],
        [335, 7551705],
        [340, 8306865],
        [345, 9137555],
        [350, 14359050],
        [355, 15794950],
        [360, 17374450],
        [365, 19111900],
        [370, 21023050],
        [375, 23125350],
        [380, 25437900],
        [385, 27981700],
        [390, 30779850],
        [395, 33857850],
        [400, 74487300],
        [405, 81936000],
        [410, 90129600],
        [415, 99142600],
        [420, 109056800],
        [425, 119962500],
        [430, 131958800],
        [435, 145154600],
        [440, 159670100],
        [445, 175637100],
        [450, 386401600],
        [455, 425041800],
        [460, 467546000],
        [465, 514300600],
        [470, 565730600],
        [475, 1244607600],
        [480, 1369068000],
        [485, 1505974800],
        [490, 2070715500],
        [495, 2277787000],
        [500, 2505566000]
    ];

    private const REWARD_XP_TABLE = [
        [5, 75],
        [10, 75],
        [15, 83],
        [20, 91],
        [25, 100],
        [30, 110],
        [35, 121],
        [40, 133],
        [45, 146],
        [50, 161],
        [55, 177],
        [60, 195],
        [65, 214],
        [70, 235],
        [75, 259],
        [80, 285],
        [85, 313],
        [90, 345],
        [95, 379],
        [100, 417],
        [105, 459],
        [110, 505],
        [115, 555],
        [120, 611],
        [125, 672],
        [130, 739],
        [135, 813],
        [140, 894],
        [145, 983],
        [150, 1082],
        [155, 1190],
        [160, 1309],
        [165, 1440],
        [170, 1584],
        [175, 1742],
        [180, 1916],
        [185, 2108],
        [190, 2318],
        [195, 2550],
        [200, 2805],
        [205, 3086],
        [210, 3395],
        [215, 3734],
        [220, 4107],
        [225, 4518],
        [230, 4970],
        [235, 5467],
        [240, 6014],
        [245, 6615],
        [250, 7276],
        [255, 8004],
        [260, 8804],
        [265, 9685],
        [270, 10653],
        [275, 11719],
        [280, 12890],
        [285, 14180],
        [290, 15597],
        [295, 17157],
        [300, 18873],
        [305, 20760],
        [310, 22836],
        [315, 25120],
        [320, 27632],
        [325, 30395],
        [330, 33434],
        [335, 36778],
        [340, 40456],
        [345, 44501],
        [350, 48951],
        [355, 53846],
        [360, 59231],
        [365, 65154],
        [370, 71670],
        [375, 78837],
        [380, 86720],
        [385, 95392],
        [390, 104931],
        [395, 115425],
        [400, 126967],
        [405, 139664],
        [410, 153630],
        [415, 168993],
        [420, 185892],
        [425, 204482],
        [430, 224930],
        [435, 247423],
        [440, 272165],
        [445, 299381],
        [450, 329320],
        [455, 362252],
        [460, 398477],
        [465, 438324],
        [470, 482157],
        [475, 530373],
        [480, 583410],
        [485, 641751],
        [490, 705926],
        [495, 776518],
        [500, 854170]
    ];

    // Number of successful kills calibrated for each level band. This is a
    // rule-table, not persisted state: it mirrors the bot's level threshold
    // model without changing any shared database table.
    private const XP_KILLS_TABLE = [
        [1, 40],
        [10, 55],
        [25, 100],
        [50, 150],
        [75, 200],
        [100, 250],
        [125, 320],
        [150, 400],
        [175, 470],
        [200, 550],
        [225, 630],
        [250, 720],
        [275, 810],
        [300, 950],
        [325, 1080],
        [350, 1250],
        [375, 1400],
        [400, 1600],
        [425, 1800],
        [450, 2000],
        [475, 2250],
        [500, 2500],
    ];

    public const CLASS_NAMES = [
        1 => 'Warrior',
        2 => 'Merchant',
        3 => 'Blacksmith',
        4 => 'Assassin',
        5 => 'War Lord',
    ];

    public static function interpolateLogLinear(array $table, float $x): float
    {
        $last = count($table) - 1;
        if ($x <= $table[0][0]) {
            $lower = $table[0];
            $upper = $table[1];
        } elseif ($x >= $table[$last][0]) {
            $lower = $table[$last - 1];
            $upper = $table[$last];
        } else {
            $lower = $table[0];
            $upper = $table[1];
            foreach ($table as $index => $pair) {
                if ($pair[0] > $x) {
                    $lower = $table[$index - 1];
                    $upper = $pair;
                    break;
                }
            }
        }
        [$x1, $y1] = $lower;
        [$x2, $y2] = $upper;
        $t = $x2 === $x1 ? 0.0 : ($x - $x1) / ($x2 - $x1);
        return max(1.0, exp(log((float) $y1) + $t * (log((float) $y2) - log((float) $y1))));
    }

    /** @param mixed $level */
    public static function levelPower($level): int
    {
        return (int) round(self::interpolateLogLinear(self::LEVEL_POWER_TABLE, max(1.0, (float) $level)), 0, PHP_ROUND_HALF_UP);
    }

    /** @param mixed $level */
    public static function nextLevelXp($level): int
    {
        $safeLevel = max(1.0, (float) $level);
        $killsNeeded = self::interpolateLogLinear(self::XP_KILLS_TABLE, $safeLevel);
        $rewardXp = self::interpolateLogLinear(self::REWARD_XP_TABLE, $safeLevel);
        return (int) round($killsNeeded * $rewardXp, 0, PHP_ROUND_HALF_UP);
    }

    /** @param mixed $bagLevel */
    public static function maxSlots($bagLevel, ?string $className, bool $noble): int
    {
        return ((int) $bagLevel * 10) + ($className === 'Merchant' ? 30 : 0) + ($noble ? 30 : 0);
    }

    public static function taxRate(?string $className, bool $noble): int
    {
        return ($noble || $className === 'Merchant') ? 0 : 9;
    }

    /** @param mixed $amount
     * @return int|float */
    public static function merchantCoinReward(?string $className, $amount)
    {
        $amount = (float) $amount;
        return $className === 'Merchant' ? $amount * 2 : $amount;
    }

    /** @param mixed $kills
     * @return array{status:string, percent:int, label:string} */
    public static function pkDetails($kills): array
    {
        $kills = (int) $kills;
        if ($kills <= 0) return ['status' => 'white', 'percent' => 1, 'label' => 'سفید (صلح‌جو)'];
        if ($kills <= 4) return ['status' => 'green', 'percent' => 5, 'label' => 'سبز (قانون‌مند)'];
        if ($kills <= 9) return ['status' => 'yellow', 'percent' => 15, 'label' => 'زرد (مشکوک)'];
        if ($kills <= 49) return ['status' => 'red', 'percent' => 25, 'label' => 'قرمز (شرور)'];
        return ['status' => 'black', 'percent' => 50, 'label' => 'سیاه (قاتل بدنام)'];
    }
}
