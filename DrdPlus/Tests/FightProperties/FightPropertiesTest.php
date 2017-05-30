<?php
namespace DrdPlus\Tests\FightProperties;

use DrdPlus\Codes\Armaments\ArmamentCode;
use DrdPlus\Codes\Armaments\BodyArmorCode;
use DrdPlus\Codes\Armaments\HelmCode;
use DrdPlus\Codes\Armaments\MeleeWeaponCode;
use DrdPlus\Codes\Armaments\RangedWeaponCode;
use DrdPlus\Codes\Armaments\ShieldCode;
use DrdPlus\Codes\Armaments\WeaponlikeCode;
use DrdPlus\Codes\DistanceUnitCode;
use DrdPlus\Codes\ItemHoldingCode;
use DrdPlus\Codes\ProfessionCode;
use DrdPlus\Codes\Body\WoundTypeCode;
use DrdPlus\CombatActions\CombatActions;
use DrdPlus\CurrentProperties\PropertiesForFight;
use DrdPlus\FightProperties\FightProperties;
use DrdPlus\Health\Inflictions\Glared;
use DrdPlus\Properties\Base\Agility;
use DrdPlus\Properties\Base\Knack;
use DrdPlus\Properties\Base\Strength;
use DrdPlus\Properties\Body\Height;
use DrdPlus\Properties\Body\Size;
use DrdPlus\Properties\Combat\Attack;
use DrdPlus\Properties\Combat\AttackNumber;
use DrdPlus\Properties\Combat\Defense;
use DrdPlus\Properties\Combat\DefenseNumber;
use DrdPlus\Properties\Combat\EncounterRange;
use DrdPlus\Properties\Combat\Fight;
use DrdPlus\Properties\Combat\FightNumber;
use DrdPlus\Properties\Combat\LoadingInRounds;
use DrdPlus\Properties\Combat\MaximalRange;
use DrdPlus\Properties\Combat\Shooting;
use DrdPlus\Properties\Derived\Speed;
use DrdPlus\Skills\Skills;
use DrdPlus\Tables\Combat\Actions\CombatActionsWithWeaponTypeCompatibilityTable;
use DrdPlus\Tables\Armaments\Armourer;
use DrdPlus\Tables\Armaments\Partials\WeaponlikeTable;
use DrdPlus\Tables\Armaments\Shields\ShieldUsageSkillTable;
use DrdPlus\Tables\Armaments\Weapons\MissingWeaponSkillTable;
use DrdPlus\Tables\Body\CorrectionByHeightTable;
use DrdPlus\Tables\Measurements\Distance\Distance;
use DrdPlus\Tables\Measurements\Distance\DistanceBonus;
use DrdPlus\Tables\Measurements\Distance\DistanceTable;
use DrdPlus\Tables\Measurements\Wounds\WoundsBonus;
use DrdPlus\Tables\Tables;
use Granam\Tests\Tools\TestWithMockery;

class FightPropertiesTest extends TestWithMockery
{
    /**
     * @test
     * @dataProvider provideUsageCombinations
     * @param bool $enemyIsFasterThanYou
     * @param bool $weaponIsTwoHandedOnly
     * @param bool $holdWeaponByTwoHands
     * @param bool $weaponIsInMainHand
     * @param bool $weaponIsShield
     * @param bool $weaponIsShooting and ranged
     * @param bool $weaponIsLongerOrSameAsShield
     * @param int $targetDistanceInMeters
     * @param int $combatActionsSpeedModifier
     * @param bool $usesSimplifiedLightingRules
     * @param int $currentMalusFromLightingContrast
     */
    public function I_can_use_it(
        $enemyIsFasterThanYou,
        $weaponIsTwoHandedOnly,
        $holdWeaponByTwoHands,
        $weaponIsInMainHand,
        $weaponIsShield,
        $weaponIsShooting, // and implicitly ranged
        $weaponIsLongerOrSameAsShield,
        $targetDistanceInMeters,
        $combatActionsSpeedModifier,
        $usesSimplifiedLightingRules,
        $currentMalusFromLightingContrast
    )
    {
        $armourer = $this->createArmourer();

        $weaponlikeCode = $weaponIsShield
            ? ShieldCode::getIt(ShieldCode::PAVISE)
            : $this->createWeapon(MeleeWeaponCode::CUDGEL, $weaponIsShooting);
        $strengthOfMainHand = Strength::getIt(987);
        $strengthOfOffhand = Strength::getIt(654);
        $baseStrengthForWeapon = $weaponIsInMainHand || $holdWeaponByTwoHands
            ? $strengthOfMainHand
            : $strengthOfOffhand;
        $strengthForWeapon = $holdWeaponByTwoHands && !$weaponIsTwoHandedOnly
            ? $baseStrengthForWeapon->add(2)
            : $baseStrengthForWeapon;
        $size = Size::getIt(456);
        $this->addCanUseArmament($armourer, $weaponlikeCode, $strengthForWeapon, $size, true, true, true, $weaponIsTwoHandedOnly);

        $shieldCode = $holdWeaponByTwoHands
            ? ShieldCode::getIt(ShieldCode::WITHOUT_SHIELD)
            : ShieldCode::getIt(ShieldCode::HEAVY_SHIELD);
        // weapon if two hands means strength for main hand for a shield (if without shield, otherwise throws exception)
        $strengthForShield = $weaponIsInMainHand && !$holdWeaponByTwoHands
            ? $strengthOfOffhand
            : $strengthOfMainHand;
        $this->addCanUseArmament($armourer, $shieldCode, $strengthForShield, $size);

        $strength = Strength::getIt(987);
        $currentProperties = $this->createCurrentProperties(
            $strength,
            $size,
            $strengthOfMainHand,
            $strengthOfOffhand,
            $speed = $this->createSpeed(4913)
        );
        $this->addAgility($currentProperties, Agility::getIt(321));
        $this->addKnack($currentProperties, Knack::getIt(7193));
        $this->addHeight($currentProperties, $this->createHeight(255));

        $wornBodyArmor = BodyArmorCode::getIt(BodyArmorCode::HOBNAILED_ARMOR);
        $this->addCanUseArmament($armourer, $wornBodyArmor, $strength, $size);
        $wornHelm = HelmCode::getIt(HelmCode::WITHOUT_HELM);
        $this->addCanUseArmament($armourer, $wornHelm, $strength, $size);
        $skills = $this->createSkills();
        $missingWeaponSkillsTable = new MissingWeaponSkillTable();
        $combatActions = $this->createCombatActions($combatActionValues = ['foo'], $usesSimplifiedLightingRules);

        // attack number
        $this->addAttackNumberMalusByStrengthWithWeaponlike(
            $armourer,
            $weaponlikeCode,
            $strengthForWeapon,
            $attackNumberMalusByStrengthWithWeapon = 442271
        );
        $this->addMalusToAttackNumberFromSkillsWithWeaponlike(
            $skills,
            $weaponlikeCode,
            $missingWeaponSkillsTable,
            false, // does not fight with two weapons
            $attackNumberMalusBySkillsWithWeapon = 3450
        );
        $this->addOffensiveness($armourer, $weaponlikeCode, $offensiveness = 12123);
        $this->addCombatActionsAttackNumber($combatActions, $combatActionsAttackNumberModifier = 8171);

        // base of wounds
        $this->addWeaponBaseOfWounds($armourer, $weaponlikeCode, $strengthForWeapon, $weaponBaseOfWounds = 91967);
        $this->addBaseOfWoundsMalusFromSkills(
            $skills,
            $weaponlikeCode,
            $missingWeaponSkillsTable,
            false, // does not fight with two weapons
            $baseOfWoundsMalusFromSkills = -12607
        );
        $this->addBaseOfWoundsBonusByHolding($armourer, $weaponlikeCode, $holdWeaponByTwoHands, $baseOfWoundsBonusForHolding = 748);
        $missingShieldSkillsTable = new ShieldUsageSkillTable();
        $tables = $this->createTables($weaponlikeCode, $combatActionValues, $armourer, $missingWeaponSkillsTable, $missingShieldSkillsTable);
        $this->addWoundsTypeOf($tables, $weaponlikeCode, WoundTypeCode::CUT);
        $this->addBaseOfWoundsModifierFromActions($combatActions, false /* weapon is not crushing */, $baseOfWoundsModifierFromActions = -1357);

        // fight number
        $fightsWithTwoWeapons = false;
        $this->addFightNumberMalusFromWeaponlikeBySkills(
            $skills,
            $weaponlikeCode,
            $missingWeaponSkillsTable,
            $fightsWithTwoWeapons,
            $fightNumberMalusFromWeapon = 44
        );
        $this->addFightNumberMalusByStrengthWithWeaponOrShield(
            $armourer,
            $weaponlikeCode,
            $strengthForWeapon,
            $fightNumberMalusByStrengthWithWeapon = 45
        );
        $this->addFightNumberMalusByStrengthWithWeaponOrShield(
            $armourer,
            $shieldCode,
            $strengthForShield,
            $fightNumberMalusByStrengthWithShield = 56
        );
        $this->addFightNumberMalusFromProtectivesBySkills(
            $skills,
            $armourer,
            $wornBodyArmor,
            $fightNumberMalusFromBodyArmor = 11,
            $wornHelm,
            $fightNumberMalusFromHelm = 22,
            $shieldCode,
            $fightNumberMalusFromShield = 33,
            $weaponIsShield ? $weaponlikeCode : null,
            $fightNumberMalusFromShieldAsWeapon = $weaponIsShield ? -4518 : 0
        );
        $weaponLength = 55;
        $shieldLength = $weaponIsLongerOrSameAsShield ? $weaponLength : 66;
        $this->addFightNumberBonusByWeaponlikeLength($armourer, $weaponlikeCode, $weaponLength, $shieldCode, $shieldLength);
        $this->addCombatActionsFightNumber($combatActions, $combatActionsFightNumberModifier = 777);

        // encounter range
        $this->addEncounterRange($armourer, $weaponlikeCode, $strengthForWeapon, $speed, $encounterRangeValue = 1824);

        // defense number
        $this->addDefenseNumberFromActions($combatActions, $enemyIsFasterThanYou, $defenseNumberModifierFromActions = -155157);
        $this->addDefenseNumberMalusByStrength($armourer, $weaponlikeCode, $strengthForWeapon, $defenseNumberMalusByStrengthWithWeapon = -518415);
        $this->addCoverOf($armourer, $weaponlikeCode, $coverOfWeapon = 6511);
        $this->addSkillsMalusToCoverWithShield(
            $skills,
            $missingShieldSkillsTable,
            $skillsMalusToCoverWithShield = -71810482
        );
        if ($weaponIsShield) {
            $skillsMalusToCoverWithWeapon = $skillsMalusToCoverWithShield;
        } else {
            $this->addSkillsMalusToCoverWithWeapon(
                $skills,
                $weaponlikeCode,
                $missingWeaponSkillsTable,
                $fightsWithTwoWeapons,
                $skillsMalusToCoverWithWeapon = -551514
            );
        }
        $this->addDefenseNumberMalusByStrength($armourer, $shieldCode, $strengthForShield, $defenseNumberMalusByStrengthWithShield = -1640);
        $this->addCoverOf($armourer, $shieldCode, $coverOfShield = 712479);

        // loading in rounds
        $expectedLoadingInRounds = 0;
        if ($weaponIsShooting) {
            $this->addLoadingInRoundsByStrengthWithRangedWeapon($armourer, $weaponlikeCode, $strengthForWeapon, $expectedLoadingInRounds = 56186);
        }

        // moved distance
        $this->addActionsSpeedModifier($combatActions, $combatActionsSpeedModifier);
        if ($combatActionsSpeedModifier !== 0) {
            $this->addDistanceTable($tables, $speed, $combatActionsSpeedModifier, $expectedMovedDistance = $this->createDistance(123456));
        } else {
            $expectedMovedDistance = $this->createDistance(0.0);
        }

        if ($holdWeaponByTwoHands) {
            $weaponHolding = ItemHoldingCode::getIt(ItemHoldingCode::TWO_HANDS);
        } elseif ($weaponIsInMainHand) {
            $weaponHolding = ItemHoldingCode::getIt(ItemHoldingCode::MAIN_HAND);
        } else {
            $weaponHolding = ItemHoldingCode::getIt(ItemHoldingCode::OFFHAND);
        }

        $fightProperties = new FightProperties(
            $currentProperties,
            $combatActions,
            $skills,
            $wornBodyArmor,
            $wornHelm,
            $professionCode = ProfessionCode::getIt(ProfessionCode::FIGHTER),
            $tables,
            $weaponlikeCode,
            $weaponHolding,
            $fightsWithTwoWeapons,
            $shieldCode,
            $enemyIsFasterThanYou,
            $this->createGlared($currentMalusFromLightingContrast)
        );

        $this->I_can_get_expected_fight_and_fight_number(
            $fightProperties,
            $professionCode,
            $currentProperties,
            $fightNumberMalusByStrengthWithWeapon,
            $fightNumberMalusByStrengthWithShield,
            $fightNumberMalusFromWeapon,
            $fightNumberMalusFromBodyArmor,
            $fightNumberMalusFromHelm,
            $fightNumberMalusFromShield,
            $fightNumberMalusFromShieldAsWeapon, // conditioned - mostly zero
            $weaponlikeCode,
            $weaponLength,
            $shieldCode,
            $shieldLength,
            $combatActionsFightNumberModifier
        );

        $targetDistance = new Distance($targetDistanceInMeters, DistanceUnitCode::METER, new DistanceTable());
        $attackNumberModifierByTargetDistance = 0;
        if ($weaponIsShooting) {
            $this->addAttackNumberModifierByDistance(
                $targetDistance,
                $armourer,
                $fightProperties->getEncounterRange(),
                $fightProperties->getMaximalRange(),
                $attackNumberModifierByTargetDistance = -8218
            );
        }
        $targetSize = Size::getIt(123);
        $attackNumberModifierByTargetSize = 0;
        if ($weaponIsShooting) {
            $this->addAttackNumberModifierBySize(
                $targetSize,
                $armourer,
                $attackNumberModifierByTargetSize = 4053
            );
        }
        $this->I_can_get_expected_shooting_attack_and_attack_number(
            $fightProperties,
            $currentProperties,
            $weaponIsShooting,
            $attackNumberMalusByStrengthWithWeapon,
            $attackNumberMalusBySkillsWithWeapon,
            $offensiveness,
            $combatActionsAttackNumberModifier,
            $targetDistance,
            $attackNumberModifierByTargetDistance,
            $targetSize,
            $attackNumberModifierByTargetSize,
            $usesSimplifiedLightingRules,
            $currentMalusFromLightingContrast
        );

        $this->I_can_get_expected_base_of_wounds(
            $fightProperties,
            $weaponBaseOfWounds,
            $baseOfWoundsMalusFromSkills,
            $baseOfWoundsBonusForHolding,
            $baseOfWoundsModifierFromActions
        );

        $this->I_can_get_expected_loading_in_rounds($fightProperties, $expectedLoadingInRounds);

        $this->I_can_get_expected_encounter_range($fightProperties, $encounterRangeValue);

        $this->I_can_get_expected_maximal_range($fightProperties, $weaponIsShooting);

        $this->I_can_get_defense_and_defense_number(
            $fightProperties,
            $currentProperties,
            $defenseNumberModifierFromActions,
            $usesSimplifiedLightingRules,
            $currentMalusFromLightingContrast,
            $defenseNumberMalusByStrengthWithWeapon,
            $coverOfWeapon,
            $skillsMalusToCoverWithWeapon,
            $defenseNumberMalusByStrengthWithShield,
            $coverOfShield,
            $skillsMalusToCoverWithShield
        );

        $this->I_can_get_moved_distance($fightProperties, $expectedMovedDistance);
    }

    public function provideUsageCombinations(): array
    {
        // enemy is faster than you, weapon is two handed only, holds weapon by two hands, weapon in main hand,
        // weapon is shield, weapon is shooting, weapon is longer or same as shield, target distance in meters,
        // speed modifier from combat actions, uses simplified lighting rules, malus from lighting contrast,
        return [
            [true, false, false, true, false, false, true, 0, 0, true, 0],
            [true, false, false, true, false, false, false, 0, 0, true, 0],
            [true, false /* not shooting */, false, false, true, false, true, 14789 /* distance should be ignored for non-ranged weapon */, 4596, false, 0],
            [false, false, true, true, false, true, false, 1, -741, true, -987654 /* malus from lighting should be ignored on simplified rules usage */],
            [false, false, true, true, false, true, true, 78515, 0, false, -123 /* odd */],
            [false, true, true, false, true, false, false, 0, 1, false, -2 /* even */],
        ];
    }

    /**
     * @param FightProperties $fightProperties
     * @param ProfessionCode $professionCode
     * @param PropertiesForFight $currentProperties
     * @param int $fightNumberMalusFromStrengthForWeapon
     * @param int $fightNumberMalusFromStrengthForShield
     * @param int $fightNumberMalusFromWeapon
     * @param int $fightNumberMalusFromBodyArmor
     * @param int $fightNumberMalusFromHelm
     * @param int $fightNumberMalusFromShield
     * @param int $fightNumberMalusFromShieldAsWeapon
     * @param WeaponlikeCode $weaponlikeCode
     * @param int $weaponlikeLength
     * @param ShieldCode $shieldCode
     * @param int $shieldLength
     * @param int $combatActionsFightNumberModifier
     */
    private function I_can_get_expected_fight_and_fight_number(
        FightProperties $fightProperties,
        ProfessionCode $professionCode,
        PropertiesForFight $currentProperties,
        $fightNumberMalusFromStrengthForWeapon,
        $fightNumberMalusFromStrengthForShield,
        $fightNumberMalusFromWeapon,
        $fightNumberMalusFromBodyArmor,
        $fightNumberMalusFromHelm,
        $fightNumberMalusFromShield,
        $fightNumberMalusFromShieldAsWeapon, // this is conditioned - mostly zero
        WeaponlikeCode $weaponlikeCode,
        $weaponlikeLength,
        ShieldCode $shieldCode,
        $shieldLength,
        $combatActionsFightNumberModifier
    )
    {
        $tables = $this->createTablesWithCorrectionByHeightTable(
            $currentProperties->getHeight(),
            -876,
            $weaponlikeCode,
            $weaponlikeLength,
            $shieldCode,
            $shieldLength
        );
        $fight = $fightProperties->getFight($tables);
        self::assertInstanceOf(Fight::class, $fight);
        self::assertSame($fight, $fightProperties->getFight($tables), 'Expected same instances');
        $expectedFight = Fight::getIt($professionCode, $currentProperties, $currentProperties->getHeight(), $tables);
        self::assertSame($expectedFight->getValue(), $fight->getValue(), __FUNCTION__ . ' expected different fight value');

        $fightNumber = $fightProperties->getFightNumber($tables);
        self::assertInstanceOf(FightNumber::class, $fightNumber);
        self::assertSame($fightNumber, $fightProperties->getFightNumber($tables), 'Expected same instances');
        $expectedFightNumber = FightNumber::getIt($expectedFight, $shieldLength > $weaponlikeLength ? $shieldCode : $weaponlikeCode, $tables)
            ->add( // fight number modifier
                $fightNumberMalusFromStrengthForWeapon
                + $fightNumberMalusFromStrengthForShield
                + $fightNumberMalusFromWeapon
                + $fightNumberMalusFromBodyArmor
                + $fightNumberMalusFromHelm
                + $fightNumberMalusFromShield
                + $fightNumberMalusFromShieldAsWeapon // this is conditioned - mostly zero
                + $combatActionsFightNumberModifier
            );
        self::assertSame($expectedFightNumber->getValue(), $fightNumber->getValue(), __FUNCTION__ . ' expected different value');
    }

    /**
     * @param Height $expectedHeight
     * @param $correctionByHeight
     * @param WeaponlikeCode $weaponlikeCode
     * @param $weaponlikeLength = null
     * @param ShieldCode $shieldCode = null
     * @param $shieldLength = null
     * @return \Mockery\MockInterface|Tables
     */
    private function createTablesWithCorrectionByHeightTable(
        Height $expectedHeight,
        $correctionByHeight,
        WeaponlikeCode $weaponlikeCode = null,
        $weaponlikeLength = null,
        ShieldCode $shieldCode = null,
        $shieldLength = null
    )
    {
        $tables = $this->mockery(Tables::class);
        $tables->shouldReceive('getCorrectionByHeightTable')
            ->andReturn($correctionByHeightTable = $this->mockery(CorrectionByHeightTable::class));
        $correctionByHeightTable->shouldReceive('getCorrectionByHeight')
            ->with($expectedHeight)
            ->andReturn($correctionByHeight);
        $tables->shouldReceive('getArmourer')
            ->andReturn($armourer = $this->mockery(Armourer::class));
        if ($weaponlikeCode) {
            $armourer->shouldReceive('getLengthOfWeaponOrShield')
                ->with($weaponlikeCode)
                ->andReturn($weaponlikeLength);
        }
        if ($shieldCode) {
            $armourer->shouldReceive('getLengthOfWeaponOrShield')
                ->with($shieldCode)
                ->andReturn($shieldLength);
        }

        return $tables;
    }

    /**
     * @param FightProperties $fightProperties
     * @param PropertiesForFight $currentProperties
     * @param bool $weaponIsRanged
     * @param int $attackNumberMalusByStrengthWithWeapon
     * @param int $attackNumberMalusBySkillsWithWeapon
     * @param int $offensiveness
     * @param int $combatActionsAttackNumberModifier
     * @param Distance $targetDistance
     * @param int $attackNumberModifierByTargetDistance
     * @param Size $targetSize
     * @param int $attackNumberModifierByTargetSize
     * @param bool $usesSimplifiedLightingRules
     * @param int $currentMalusFromLightingContrast
     */
    private function I_can_get_expected_shooting_attack_and_attack_number(
        FightProperties $fightProperties,
        PropertiesForFight $currentProperties,
        $weaponIsRanged,
        $attackNumberMalusByStrengthWithWeapon,
        $attackNumberMalusBySkillsWithWeapon,
        $offensiveness,
        $combatActionsAttackNumberModifier,
        Distance $targetDistance,
        $attackNumberModifierByTargetDistance,
        Size $targetSize,
        $attackNumberModifierByTargetSize,
        $usesSimplifiedLightingRules,
        $currentMalusFromLightingContrast
    )
    {
        $shooting = $fightProperties->getShooting();
        self::assertInstanceOf(Shooting::class, $shooting);
        $expectedShooting = Shooting::getIt($currentProperties->getKnack());
        self::assertSame($expectedShooting->getValue(), $shooting->getValue());

        $attack = $fightProperties->getAttack();
        self::assertInstanceOf(Attack::class, $attack);
        $expectedAttack = Attack::getIt($currentProperties->getAgility());
        self::assertSame($expectedAttack->getValue(), $attack->getValue());

        $attackNumber = $fightProperties->getAttackNumber($targetDistance, $targetSize);
        self::assertInstanceOf(AttackNumber::class, $attackNumber);

        $expectedBaseAttackNumber = $weaponIsRanged
            ? AttackNumber::getItFromShooting(Shooting::getIt($currentProperties->getKnack()))
            : AttackNumber::getItFromAttack(Attack::getIt($currentProperties->getAgility()));
        $expectedAttackNumber = $expectedBaseAttackNumber->add(
            $attackNumberMalusByStrengthWithWeapon
            + $attackNumberMalusBySkillsWithWeapon
            + $offensiveness
            + $combatActionsAttackNumberModifier
            + $attackNumberModifierByTargetDistance
            + $attackNumberModifierByTargetSize
            + ($usesSimplifiedLightingRules ? 0 : round($currentMalusFromLightingContrast / 2 /* just half */))
        );
        self::assertSame(
            $expectedAttackNumber->getValue(),
            $attackNumber->getValue(),
            "Expected attack number {$expectedAttackNumber} on distance {$targetDistance}"
        );
    }

    /**
     * @param FightProperties $fightProperties
     * @param int $weaponBaseOfWounds
     * @param int $baseOfWoundsMalusFromSkills
     * @param int $baseOfWoundsBonusForHolding
     * @param int $baseOfWoundsModifierFromActions
     */
    private function I_can_get_expected_base_of_wounds(
        FightProperties $fightProperties,
        $weaponBaseOfWounds,
        $baseOfWoundsMalusFromSkills,
        $baseOfWoundsBonusForHolding,
        $baseOfWoundsModifierFromActions
    )
    {
        $baseOfWounds = $fightProperties->getBaseOfWounds();
        self::assertInstanceOf(WoundsBonus::class, $baseOfWounds);
        self::assertSame($baseOfWounds, $fightProperties->getBaseOfWounds(), 'Expected same instances');
        $expectedBaseOfWoundsValue = $weaponBaseOfWounds + $baseOfWoundsMalusFromSkills + $baseOfWoundsBonusForHolding
            + $baseOfWoundsModifierFromActions;
        self::assertSame($baseOfWounds->getValue(), $expectedBaseOfWoundsValue);
    }

    /**
     * @param FightProperties $fightProperties
     * @param int $expectedLoadingInRounds
     */
    private function I_can_get_expected_loading_in_rounds(FightProperties $fightProperties, $expectedLoadingInRounds)
    {
        $loadingInRounds = $fightProperties->getLoadingInRounds();
        self::assertInstanceOf(LoadingInRounds::class, $loadingInRounds);
        self::assertSame($expectedLoadingInRounds, $loadingInRounds->getValue());
        self::assertSame($loadingInRounds, $fightProperties->getLoadingInRounds(), 'Expected same instances');
    }

    /**
     * @param FightProperties $fightProperties
     * @param int $encounterRangeValue
     */
    private function I_can_get_expected_encounter_range(
        FightProperties $fightProperties,
        $encounterRangeValue
    )
    {
        $encounterRange = $fightProperties->getEncounterRange();
        self::assertInstanceOf(EncounterRange::class, $encounterRange);
        self::assertSame($encounterRangeValue, $encounterRange->getValue());
        self::assertSame($encounterRange, $fightProperties->getEncounterRange(), 'Expected same instances');
    }

    /**
     * @param FightProperties $fightProperties
     * @param bool $isRanged
     */
    private function I_can_get_expected_maximal_range(FightProperties $fightProperties, $isRanged)
    {
        $expectedMaximalRange = $isRanged
            ? MaximalRange::getItForRangedWeapon($fightProperties->getEncounterRange())
            : MaximalRange::getItForMeleeWeapon($fightProperties->getEncounterRange());
        $maximalRange = $fightProperties->getMaximalRange();
        self::assertInstanceOf(MaximalRange::class, $maximalRange);
        self::assertSame(
            $expectedMaximalRange->getValue(),
            $maximalRange->getValue(),
            "Expected maximal range {$expectedMaximalRange->getValue()}"
        );
        self::assertSame($maximalRange, $fightProperties->getMaximalRange(), 'Expected same instances');
    }

    /**
     * @param FightProperties $fightProperties
     * @param PropertiesForFight $currentProperties
     * @param int $defenseNumberModifierFromCombatActions
     * @param bool $usesSimplifiedLightingRules
     * @param int $currentMalusFromLightingContrast
     * @param int $defenseNumberMalusByStrengthWithWeapon
     * @param int $coverOfWeapon
     * @param int $skillsMalusToCoverWithWeapon
     * @param int $defenseNumberMalusByStrengthWithShield
     * @param int $coverOfShield
     * @param int $skillsMalusToCoverWithShield
     */
    private function I_can_get_defense_and_defense_number(
        FightProperties $fightProperties,
        PropertiesForFight $currentProperties,
        $defenseNumberModifierFromCombatActions,
        $usesSimplifiedLightingRules,
        $currentMalusFromLightingContrast,
        $defenseNumberMalusByStrengthWithWeapon,
        $coverOfWeapon,
        $skillsMalusToCoverWithWeapon,
        $defenseNumberMalusByStrengthWithShield,
        $coverOfShield,
        $skillsMalusToCoverWithShield
    )
    {
        $defense = $fightProperties->getDefense();
        self::assertInstanceOf(Defense::class, $defense);
        $expectedDefense = Defense::getIt($currentProperties->getAgility());
        self::assertSame($expectedDefense->getValue(), $defense->getValue());

        self::assertInstanceOf(DefenseNumber::class, $fightProperties->getDefenseNumber());
        $expectedDefenseNumber = DefenseNumber::getIt(Defense::getIt($currentProperties->getAgility()))
            ->add($defenseNumberModifierFromCombatActions + ($usesSimplifiedLightingRules ? 0 : $currentMalusFromLightingContrast));
        self::assertSame($expectedDefenseNumber->getValue(), $fightProperties->getDefenseNumber()->getValue());

        $expectedDefenseNumberWithWeapon = $expectedDefenseNumber->add(
            $defenseNumberMalusByStrengthWithWeapon
            + $coverOfWeapon
            + $skillsMalusToCoverWithWeapon
        );
        self::assertSame(
            $expectedDefenseNumberWithWeapon->getValue(),
            $fightProperties->getDefenseNumberWithWeaponlike()->getValue(),
            "Expected defense number with weapon to be {$expectedDefenseNumberWithWeapon->getValue()}"
        );

        $expectedDefenseNumberWithShield = $expectedDefenseNumber->add(
            $defenseNumberMalusByStrengthWithShield
            + $coverOfShield
            + $skillsMalusToCoverWithShield
        );
        self::assertSame(
            $expectedDefenseNumberWithShield->getValue(),
            $fightProperties->getDefenseNumberWithShield()->getValue()
        );
    }

    /**
     * @param FightProperties $fightProperties
     * @param Distance $expectedMovedDistance
     */
    private function I_can_get_moved_distance(FightProperties $fightProperties, Distance $expectedMovedDistance)
    {
        $movedDistance = $fightProperties->getMovedDistance();
        self::assertInstanceOf(Distance::class, $movedDistance);
        self::assertSame($expectedMovedDistance->getValue(), $movedDistance->getValue());
        self::assertSame($movedDistance, $fightProperties->getMovedDistance(), 'Same instances expected');
    }

    /**
     * @return \Mockery\MockInterface|Armourer
     */
    private function createArmourer()
    {
        return $this->mockery(Armourer::class);
    }

    /**
     * @param int $currentMalus
     * @return \Mockery\MockInterface|Glared
     */
    private function createGlared($currentMalus = 0)
    {
        $glared = $this->mockery(Glared::class);
        $glared->shouldReceive('getCurrentMalus')
            ->andReturn($currentMalus);

        return $glared;
    }

    /**
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param ArmamentCode $armamentCode
     * @param Strength $expectedStrength
     * @param Size $size
     * @param bool $canUseArmament
     * @param bool $canHoldItByOneHand
     * @param bool $canHoldItByTwoHands
     * @param bool $isTwoHandedOnly
     * @return Armourer
     */
    private function addCanUseArmament(
        Armourer $armourer,
        ArmamentCode $armamentCode,
        Strength $expectedStrength,
        Size $size,
        $canUseArmament = true,
        $canHoldItByOneHand = true,
        $canHoldItByTwoHands = true,
        $isTwoHandedOnly = false
    ): Armourer
    {
        /** @noinspection PhpUnusedParameterInspection */
        $armourer->shouldReceive('canUseArmament')
            ->with($armamentCode, \Mockery::type(Strength::class), $size)
            ->andReturnUsing(
                function (ArmamentCode $armamentCode, Strength $strength, Size $size) use ($expectedStrength, $canUseArmament) {
                    self::assertSame(
                        $expectedStrength->getValue(),
                        $strength->getValue(),
                        "Expected strength {$expectedStrength->getValue()}, got {$strength->getValue()} for {$armamentCode}"
                    );

                    return $canUseArmament;
                }
            );
        $armourer->shouldReceive('canHoldItByOneHand')
            ->with($armamentCode)
            ->andReturn($canHoldItByOneHand);
        $armourer->shouldReceive('canHoldItByTwoHands')
            ->with($armamentCode)
            ->andReturn($canHoldItByTwoHands);
        $armourer->shouldReceive('isTwoHandedOnly')
            ->with($armamentCode)
            ->andReturn($isTwoHandedOnly);

        return $armourer;
    }

    /**
     * @param Strength $strength
     * @param Size $size
     * @param Strength $strengthOfMainHand
     * @param Strength $strengthOfOffhand
     * @param Speed $speed
     * @return \Mockery\MockInterface|PropertiesForFight
     */
    private function createCurrentProperties(
        Strength $strength,
        Size $size,
        Strength $strengthOfMainHand,
        Strength $strengthOfOffhand,
        Speed $speed = null
    )
    {
        $currentProperties = $this->mockery(PropertiesForFight::class);
        $currentProperties->shouldReceive('getStrength')
            ->andReturn($strength);
        $currentProperties->shouldReceive('getSize')
            ->andReturn($size);
        $currentProperties->shouldReceive('getStrengthOfMainHand')
            ->andReturn($strengthOfMainHand);
        $currentProperties->shouldReceive('getStrengthOfOffhand')
            ->andReturn($strengthOfOffhand);
        if ($speed !== null) {
            $currentProperties->shouldReceive('getSpeed')
                ->andReturn($speed);
        }

        return $currentProperties;
    }

    /**
     * @param array $values
     * @param bool $usesSimplifiedLightingRules
     * @return \Mockery\MockInterface|CombatActions
     */
    private function createCombatActions(array $values, $usesSimplifiedLightingRules = false)
    {
        $combatActions = $this->mockery(CombatActions::class);
        $combatActions->shouldReceive('getIterator')
            ->andReturn(new \ArrayIterator($values));
        $combatActions->shouldReceive('usesSimplifiedLightingRules')
            ->andReturn($usesSimplifiedLightingRules);

        return $combatActions;
    }

    /**
     * @return \Mockery\MockInterface|Skills
     */
    private function createSkills()
    {
        return $this->mockery(Skills::class);
    }

    /**
     * @param WeaponlikeCode $weaponlikeCode
     * @param array $possibleActions
     * @param Armourer $armourer
     * @param MissingWeaponSkillTable $missingWeaponSkillsTable
     * @param ShieldUsageSkillTable $missingShieldSkillsTable
     * @return \Mockery\MockInterface|Tables
     */
    private function createTables(
        WeaponlikeCode $weaponlikeCode,
        array $possibleActions,
        Armourer $armourer,
        MissingWeaponSkillTable $missingWeaponSkillsTable = null,
        ShieldUsageSkillTable $missingShieldSkillsTable = null
    )
    {
        $tables = $this->mockery(Tables::class);
        $tables->shouldReceive('getCombatActionsWithWeaponTypeCompatibilityTable')
            ->andReturn($compatibilityTable = $this->mockery(CombatActionsWithWeaponTypeCompatibilityTable::class));
        $compatibilityTable->shouldReceive('getActionsPossibleWhenFightingWith')
            ->with($weaponlikeCode)
            ->andReturn($possibleActions);
        $tables->shouldReceive('getArmourer')
            ->andReturn($armourer);
        if ($missingWeaponSkillsTable) {
            $tables->shouldReceive('getMissingWeaponSkillTable')
                ->andReturn($missingWeaponSkillsTable);
        }
        if ($missingShieldSkillsTable) {
            $tables->shouldReceive('getShieldUsageSkillTable')
                ->andReturn($missingShieldSkillsTable);
        }
        $tables->shouldDeferMissing();

        return $tables;
    }

    /**
     * @param Tables|\Mockery\MockInterface $tables
     * @param WeaponlikeCode $weaponlikeCode
     * @param string $woundType
     */
    private function addWoundsTypeOf(Tables $tables, WeaponlikeCode $weaponlikeCode, $woundType)
    {
        $tables->shouldReceive('getWeaponlikeTableByWeaponlikeCode')
            ->with($weaponlikeCode)
            ->andReturn($weaponlikeTable = $this->mockery(WeaponlikeTable::class));
        $weaponlikeTable->shouldReceive('getWoundsTypeOf')
            ->with($weaponlikeCode)
            ->andReturn($woundType);
    }

    /**
     * @param string $name
     * @param bool $isRangedAndShooting
     * @return \Mockery\MockInterface|RangedWeaponCode|MeleeWeaponCode
     */
    private function createWeapon($name = 'foo', $isRangedAndShooting = false)
    {
        $weaponlikeCode = $this->mockery($isRangedAndShooting ? RangedWeaponCode::class : MeleeWeaponCode::class);
        $weaponlikeCode->shouldReceive('__toString')
            ->andReturn($name);
        $weaponlikeCode->shouldReceive('isShield')
            ->andReturn(false);
        $weaponlikeCode->shouldReceive('isShootingWeapon')
            ->andReturn($isRangedAndShooting);
        $weaponlikeCode->shouldReceive('isRanged')
            ->andReturn($isRangedAndShooting);

        return $weaponlikeCode;
    }

    /**
     * @param bool $holdsByTwoHands
     * @param bool $holdsByMainHand
     * @param bool $holdsByOffhand
     * @return \Mockery\MockInterface|ItemHoldingCode
     */
    private function createWeaponlikeHolding($holdsByTwoHands, $holdsByMainHand, $holdsByOffhand)
    {
        $itemHolding = $this->mockery(ItemHoldingCode::class);
        $itemHolding->shouldReceive('holdsByTwoHands')
            ->andReturn($holdsByTwoHands);
        $itemHolding->shouldReceive('holdsByOneHand')
            ->andReturn(!$holdsByTwoHands);
        $itemHolding->shouldReceive('holdsByMainHand')
            ->andReturn($holdsByMainHand);
        $itemHolding->shouldReceive('holdsByOffhand')
            ->andReturn($holdsByOffhand);

        return $itemHolding;
    }

    /**
     * @return \Mockery\MockInterface|ShieldCode
     */
    private function createShieldCode()
    {
        return $this->mockery(ShieldCode::class);
    }

    private function addAgility(\Mockery\MockInterface $mock, Agility $agility)
    {
        $mock->shouldReceive('getAgility')
            ->andReturn($agility);
    }

    private function addKnack(\Mockery\MockInterface $mock, Knack $knack)
    {
        $mock->shouldReceive('getKnack')
            ->andReturn($knack);
    }

    private function addHeight(\Mockery\MockInterface $mock, Height $height)
    {
        $mock->shouldReceive('getHeight')
            ->andReturn($height);
    }

    /**
     * @param $value
     * @return Height|\Mockery\MockInterface
     */
    private function createHeight($value)
    {
        $height = $this->mockery(Height::class);
        $height->shouldReceive('getValue')
            ->andReturn($value);
        $height->shouldReceive('__toString')
            ->andReturn((string)$value);

        return $height;
    }

    /**
     * @see FightProperties::getAttackNumberModifier
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param Strength $expectedStrength
     * @param int $attackNumberMalus
     */
    private function addAttackNumberMalusByStrengthWithWeaponlike(
        Armourer $armourer,
        WeaponlikeCode $weaponlikeCode,
        Strength $expectedStrength,
        $attackNumberMalus
    )
    {
        /** @noinspection PhpUnusedParameterInspection */
        $armourer->shouldReceive('getAttackNumberMalusByStrengthWithWeaponlike')
            ->with($weaponlikeCode, \Mockery::type(Strength::class))
            ->andReturnUsing(
                function (WeaponlikeCode $weaponlikeCode, Strength $strength) use ($attackNumberMalus, $expectedStrength) {
                    self::assertSame($expectedStrength->getValue(), $strength->getValue());

                    return $attackNumberMalus;
                }
            );
    }

    /**
     * @see FightProperties::getAttackNumberModifier
     * @param Skills|\Mockery\MockInterface $skills
     * @param WeaponlikeCode $expectedWeaponlikeCode
     * @param MissingWeaponSkillTable $expectedMissingWeaponSkillTable
     * @param bool $fightsWithTwoWeapons
     * @param int $attackNumberMalus
     */
    private function addMalusToAttackNumberFromSkillsWithWeaponlike(
        Skills $skills,
        WeaponlikeCode $expectedWeaponlikeCode,
        MissingWeaponSkillTable $expectedMissingWeaponSkillTable,
        $fightsWithTwoWeapons,
        $attackNumberMalus
    )
    {
        /** @noinspection PhpUnusedParameterInspection */
        $skills->shouldReceive('getMalusToAttackNumberWithWeaponlike')
            ->with($expectedWeaponlikeCode, $this->type(Tables::class), $fightsWithTwoWeapons)
            ->andReturnUsing(
                function (WeaponlikeCode $weaponlikeCode, Tables $tables, $fightsWithTwoWeapons)
                use ($expectedMissingWeaponSkillTable, $attackNumberMalus) {
                    self::assertSame($expectedMissingWeaponSkillTable, $tables->getMissingWeaponSkillTable());

                    return $attackNumberMalus;
                }
            );
    }

    /**
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param $offensiveness
     */
    private function addOffensiveness(Armourer $armourer, WeaponlikeCode $weaponlikeCode, $offensiveness)
    {
        $armourer->shouldReceive('getOffensivenessOfWeaponlike')
            ->with($weaponlikeCode)
            ->andReturn($offensiveness);
    }

    /**
     * @param CombatActions|\Mockery\MockInterface $combatActions
     * @param $attackNumberModifier
     */
    private function addCombatActionsAttackNumber(CombatActions $combatActions, $attackNumberModifier)
    {
        $combatActions->shouldReceive('getAttackNumberModifier')
            ->andReturn($attackNumberModifier);
    }

    /**
     * @param CombatActions|\Mockery\MockInterface $combatActions
     * @param $attackNumberModifier
     */
    private function addCombatActionsFightNumber(CombatActions $combatActions, $attackNumberModifier)
    {
        $combatActions->shouldReceive('getFightNumberModifier')
            ->andReturn($attackNumberModifier);
    }

    /**
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param Strength $expectedStrength
     * @param Speed $speed
     * @param $encounterRangeValue
     */
    private function addEncounterRange(
        Armourer $armourer,
        WeaponlikeCode $weaponlikeCode,
        Strength $expectedStrength,
        Speed $speed,
        $encounterRangeValue
    )
    {
        /** @noinspection PhpUnusedParameterInspection */
        $armourer->shouldReceive('getEncounterRangeWithWeaponlike')
            ->with($weaponlikeCode, \Mockery::type(Strength::class), $speed)
            ->andReturnUsing(
                function (WeaponlikeCode $weaponlikeCode, Strength $strength, Speed $speed)
                use ($encounterRangeValue, $expectedStrength) {
                    self::assertSame($expectedStrength->getValue(), $strength->getValue());

                    return $encounterRangeValue;
                }
            );
    }

    /**
     * @param CombatActions|\Mockery\MockInterface $combatActions
     * @param bool $enemyIsFasterThanYou
     * @param int $defenseNumberModifier
     */
    private function addDefenseNumberFromActions(CombatActions $combatActions, $enemyIsFasterThanYou, $defenseNumberModifier)
    {
        $combatActions->shouldReceive($enemyIsFasterThanYou
            ? 'getDefenseNumberModifierAgainstFasterOpponent'
            : 'getDefenseNumberModifier'
        )
            ->andReturn($defenseNumberModifier);
    }

    /**
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param Strength $expectedStrength
     * @param int $defenseNumberMalus
     */
    private function addDefenseNumberMalusByStrength(
        Armourer $armourer,
        WeaponlikeCode $weaponlikeCode,
        Strength $expectedStrength,
        $defenseNumberMalus
    )
    {
        /** @noinspection PhpUnusedParameterInspection */
        $armourer->shouldReceive('getDefenseNumberMalusByStrengthWithWeaponOrShield')
            ->with($weaponlikeCode, \Mockery::type(Strength::class))
            ->andReturnUsing(
                function (WeaponlikeCode $weaponlikeCode, Strength $strength) use ($defenseNumberMalus, $expectedStrength) {
                    self::assertSame(
                        $expectedStrength->getValue(),
                        $strength->getValue(),
                        "Expected strength {$expectedStrength}, got {$strength} for {$weaponlikeCode}"
                    );

                    return $defenseNumberMalus;
                }
            );
    }

    /**
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param int $coverOfWeapon
     */
    private function addCoverOf(Armourer $armourer, WeaponlikeCode $weaponlikeCode, $coverOfWeapon)
    {
        $armourer->shouldReceive('getCoverOfWeaponOrShield')
            ->with($weaponlikeCode)
            ->andReturn($coverOfWeapon);
    }

    /**
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param Strength $expectedStrength
     * @param int $loadingInRounds
     */
    private function addLoadingInRoundsByStrengthWithRangedWeapon(
        Armourer $armourer,
        WeaponlikeCode $weaponlikeCode,
        Strength $expectedStrength,
        $loadingInRounds
    )
    {
        $armourer->shouldReceive('getLoadingInRoundsByStrengthWithRangedWeapon')
            ->with($weaponlikeCode, \Mockery::type(Strength::class))
            ->andReturnUsing(function (RangedWeaponCode $weaponlikeCode, Strength $strength) use ($expectedStrength, $loadingInRounds) {
                self::assertSame(
                    $expectedStrength->getValue(),
                    $strength->getValue(),
                    "Expected strength {$expectedStrength} for weapon {$weaponlikeCode}"
                );

                return $loadingInRounds;
            });
    }

    /**
     * @param Skills|\Mockery\MockInterface $skills
     * @param WeaponlikeCode $weaponlikeCode
     * @param MissingWeaponSkillTable $expectedMissingWeaponSkillTable
     * @param bool $fightsWithTwoWeapons
     * @param int $skillsMalusToCoverWithWeapon
     */
    private function addSkillsMalusToCoverWithWeapon(
        Skills $skills,
        WeaponlikeCode $weaponlikeCode,
        MissingWeaponSkillTable $expectedMissingWeaponSkillTable,
        $fightsWithTwoWeapons,
        $skillsMalusToCoverWithWeapon
    )
    {
        /** @noinspection PhpUnusedParameterInspection */
        $skills->shouldReceive('getMalusToCoverWithWeapon')
            ->with($weaponlikeCode, $this->type(Tables::class), $fightsWithTwoWeapons)
            ->andReturnUsing(
                function (WeaponlikeCode $weaponlikeCode, Tables $tables, $fightsWithTwoWeapons)
                use ($expectedMissingWeaponSkillTable, $skillsMalusToCoverWithWeapon) {
                    self::assertSame($expectedMissingWeaponSkillTable, $tables->getMissingWeaponSkillTable());

                    return $skillsMalusToCoverWithWeapon;
                }
            );
    }

    /**
     * @param Skills|\Mockery\MockInterface $skills
     * @param ShieldUsageSkillTable $expectedShieldUsageSkillTable
     * @param int $skillsMalusToCoverWithShield
     */
    private function addSkillsMalusToCoverWithShield(
        Skills $skills,
        ShieldUsageSkillTable $expectedShieldUsageSkillTable,
        $skillsMalusToCoverWithShield
    )
    {
        $skills->shouldReceive('getMalusToCoverWithShield')
            ->with($this->type(Tables::class))
            ->andReturnUsing(
                function (Tables $tables)
                use ($expectedShieldUsageSkillTable, $skillsMalusToCoverWithShield) {
                    self::assertSame($expectedShieldUsageSkillTable, $tables->getShieldUsageSkillTable());

                    return $skillsMalusToCoverWithShield;
                }
            );
    }

    /**
     * @see FightProperties::getFightNumberMalusByStrength
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $expectedWeaponlikeCode
     * @param Strength $expectedStrength
     * @param int $fightNumberMalusByStrengthWithWeapon
     */
    private function addFightNumberMalusByStrengthWithWeaponOrShield(
        Armourer $armourer,
        WeaponlikeCode $expectedWeaponlikeCode,
        Strength $expectedStrength,
        $fightNumberMalusByStrengthWithWeapon
    )
    {
        /** @noinspection PhpUnusedParameterInspection */
        $armourer->shouldReceive('getFightNumberMalusByStrengthWithWeaponOrShield')
            ->with($expectedWeaponlikeCode, \Mockery::type(Strength::class))
            ->andReturnUsing(
                function (WeaponlikeCode $expectedWeaponlikeCode, Strength $strength)
                use ($fightNumberMalusByStrengthWithWeapon, $expectedStrength) {
                    self::assertSame($expectedStrength->getValue(), $strength->getValue());

                    return $fightNumberMalusByStrengthWithWeapon;
                }
            );
    }

    /**
     * @see FightProperties::getFightNumberMalusFromProtectivesBySkills
     * @param Skills|\Mockery\MockInterface $skills
     * @param Armourer $armourer
     * @param BodyArmorCode $bodyArmorCode
     * @param int $malusToFightNumberWithBodyArmor
     * @param HelmCode $helmCode
     * @param $malusToFightNumberWithHelm
     * @param ShieldCode $shieldCode
     * @param $malusToFightNumberWithShield
     * @param ShieldCode $shieldAsWeapon = null
     * @param int $malusToFightNumberWithShieldAsWeapon = null
     */
    private function addFightNumberMalusFromProtectivesBySkills(
        Skills $skills,
        Armourer $armourer,
        BodyArmorCode $bodyArmorCode,
        $malusToFightNumberWithBodyArmor,
        HelmCode $helmCode,
        $malusToFightNumberWithHelm,
        ShieldCode $shieldCode,
        $malusToFightNumberWithShield,
        ShieldCode $shieldAsWeapon = null,
        $malusToFightNumberWithShieldAsWeapon = null
    )
    {
        $skills->shouldReceive('getMalusToFightNumberWithProtective')
            ->with($bodyArmorCode, $armourer)
            ->andReturn($malusToFightNumberWithBodyArmor);
        $skills->shouldReceive('getMalusToFightNumberWithProtective')
            ->with($helmCode, $armourer)
            ->andReturn($malusToFightNumberWithHelm);
        $skills->shouldReceive('getMalusToFightNumberWithProtective')
            ->with($shieldCode, $armourer)
            ->andReturn($malusToFightNumberWithShield);
        if ($shieldAsWeapon) {
            $skills->shouldReceive('getMalusToFightNumberWithProtective')
                ->with($shieldAsWeapon, $armourer)
                ->andReturn($malusToFightNumberWithShieldAsWeapon);
        }
    }

    /**
     * @see FightProperties::getFightNumberMalusFromWeaponlikesBySkills
     * @param Skills|\Mockery\MockInterface $skills
     * @param WeaponlikeCode $weaponlikeCode
     * @param MissingWeaponSkillTable $expectedMissingWeaponSkillTable
     * @param bool $fightsWithTwoWeapons ,
     * @param int $malusFromWeaponlike
     */
    private function addFightNumberMalusFromWeaponlikeBySkills(
        Skills $skills,
        WeaponlikeCode $weaponlikeCode,
        MissingWeaponSkillTable $expectedMissingWeaponSkillTable,
        $fightsWithTwoWeapons,
        $malusFromWeaponlike
    )
    {
        /** @noinspection PhpUnusedParameterInspection */
        $skills->shouldReceive('getMalusToFightNumberWithWeaponlike')
            ->with($weaponlikeCode, $this->type(Tables::class), $fightsWithTwoWeapons)
            ->andReturnUsing(
                function (WeaponlikeCode $weaponlikeCode, Tables $tables, $fightsWithTwoWeapons)
                use ($expectedMissingWeaponSkillTable, $malusFromWeaponlike) {
                    self::assertSame($expectedMissingWeaponSkillTable, $tables->getMissingWeaponSkillTable());

                    return $malusFromWeaponlike;
                }
            );
    }

    /**
     * @see FightProperties::getLongerWeaponlike
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param int $lengthOfWeaponlike
     * @param ShieldCode $shieldCode
     * @param int $lengthOfShield
     */
    private function addFightNumberBonusByWeaponlikeLength(
        Armourer $armourer,
        WeaponlikeCode $weaponlikeCode,
        $lengthOfWeaponlike,
        ShieldCode $shieldCode,
        $lengthOfShield
    )
    {
        $armourer->shouldReceive('getLengthOfWeaponOrShield')
            ->with($weaponlikeCode)
            ->andReturn($lengthOfWeaponlike);
        $armourer->shouldReceive('getLengthOfWeaponOrShield')
            ->with($shieldCode)
            ->andReturn($lengthOfShield);
    }

    /**
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param Strength $expectedStrength
     * @param int $baseOfWounds
     */
    private function addWeaponBaseOfWounds(Armourer $armourer, WeaponlikeCode $weaponlikeCode, Strength $expectedStrength, $baseOfWounds)
    {
        /** @noinspection PhpUnusedParameterInspection */
        $armourer->shouldReceive('getBaseOfWoundsUsingWeaponlike')
            ->with($weaponlikeCode, \Mockery::type(Strength::class))
            ->andReturnUsing(
                function (WeaponlikeCode $weaponlikeCode, Strength $strength) use ($baseOfWounds, $expectedStrength) {
                    self::assertSame($expectedStrength->getValue(), $strength->getValue());

                    return $baseOfWounds;
                }
            );
    }

    /**
     * @param Skills|\Mockery\MockInterface $skills
     * @param WeaponlikeCode $weaponlikeCode
     * @param MissingWeaponSkillTable $expectedMissingWeaponSkillTable
     * @param $fightsWithTwoWeapons
     * @param $baseOfWoundsMalusFromSkills
     */
    private function addBaseOfWoundsMalusFromSkills(
        Skills $skills,
        WeaponlikeCode $weaponlikeCode,
        MissingWeaponSkillTable $expectedMissingWeaponSkillTable,
        $fightsWithTwoWeapons,
        $baseOfWoundsMalusFromSkills
    )
    {
        /** @noinspection PhpUnusedParameterInspection */
        $skills->shouldReceive('getMalusToBaseOfWoundsWithWeaponlike')
            ->with($weaponlikeCode, $this->type(Tables::class), $fightsWithTwoWeapons)
            ->andReturnUsing(
                function (WeaponlikeCode $weaponlikeCode, Tables $tables, $fightsWithTwoWeapons)
                use ($expectedMissingWeaponSkillTable, $baseOfWoundsMalusFromSkills) {
                    self::assertSame($expectedMissingWeaponSkillTable, $tables->getMissingWeaponSkillTable());

                    return $baseOfWoundsMalusFromSkills;
                }
            );
    }

    /**
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param WeaponlikeCode $weaponlikeCode
     * @param $holdsByTwoHands
     * @param int $bonusFromHolding
     */
    private function addBaseOfWoundsBonusByHolding(
        Armourer $armourer,
        WeaponlikeCode $weaponlikeCode,
        $holdsByTwoHands,
        $bonusFromHolding
    )
    {
        $armourer->shouldReceive('getBaseOfWoundsBonusForHolding')
            ->with($weaponlikeCode, $holdsByTwoHands)
            ->andReturn($bonusFromHolding);
    }

    /**
     * @param CombatActions|\Mockery\MockInterface $combatActions
     * @param $weaponIsCrushing
     * @param int $baseOfWoundsModifierFromActions
     */
    private function addBaseOfWoundsModifierFromActions(CombatActions $combatActions, $weaponIsCrushing, $baseOfWoundsModifierFromActions)
    {
        $combatActions->shouldReceive('getBaseOfWoundsModifier')
            ->with($weaponIsCrushing)
            ->andReturn($baseOfWoundsModifierFromActions);
    }

    /**
     * @param CombatActions|\Mockery\MockInterface $combatActions
     * @param int $speedModifier
     */
    private function addActionsSpeedModifier(CombatActions $combatActions, $speedModifier)
    {
        $combatActions->shouldReceive('getSpeedModifier')
            ->andReturn($speedModifier);
    }

    /**
     * @param Tables|\Mockery\MockInterface $tables
     * @param Speed $speed
     * @param int $speedModifier
     * @param Distance $movedDistance
     */
    private function addDistanceTable(Tables $tables, Speed $speed, $speedModifier, Distance $movedDistance)
    {
        $tables->shouldReceive('getDistanceTable')
            ->andReturn($distanceTable = $this->mockery(DistanceTable::class));
        $distanceTable->shouldReceive('toDistance')
            ->with(\Mockery::type(DistanceBonus::class))
            ->andReturnUsing(function (DistanceBonus $distanceBonus) use ($speed, $speedModifier, $movedDistance) {
                self::assertSame($distanceBonus->getValue(), $speed->getValue() + $speedModifier);

                return $movedDistance;
            });
    }

    /**
     * @param float $value
     * @return \Mockery\MockInterface|Distance
     */
    private function createDistance($value = null)
    {
        $distance = $this->mockery(Distance::class);
        if ($value !== null) {
            $distance->shouldReceive('getValue')
                ->andReturn($value);
        }

        return $distance;
    }

    /**
     * @param Distance $distance
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param EncounterRange $encounterRange
     * @param MaximalRange $maximalRange
     * @param int $modifierByDistance
     */
    private function addAttackNumberModifierByDistance(
        Distance $distance,
        Armourer $armourer,
        EncounterRange $encounterRange,
        MaximalRange $maximalRange,
        $modifierByDistance
    )
    {
        $armourer->shouldReceive('getAttackNumberModifierByDistance')
            ->with($distance, $encounterRange, $maximalRange)
            ->andReturn($modifierByDistance);
    }

    /**
     * @param Size $targetSize
     * @param Armourer|\Mockery\MockInterface $armourer
     * @param int $modifierBySize
     */
    private function addAttackNumberModifierBySize(Size $targetSize, Armourer $armourer, $modifierBySize)
    {
        $armourer->shouldReceive('getAttackNumberModifierBySize')
            ->with($targetSize)
            ->andReturn($modifierBySize);
    }

    /**
     * @param int $value
     * @param bool $canBeIncreased
     * @return \Mockery\MockInterface|Speed
     */
    private function createSpeed($value = null, $canBeIncreased = true)
    {
        $speed = $this->mockery(Speed::class);
        if ($value !== null) {
            $speed->shouldReceive('getValue')
                ->andReturn($value);
        }
        if ($canBeIncreased) {
            $speed->shouldReceive('add')
                ->andReturnUsing(function ($valueToAdd) use ($value) {
                    return $this->createSpeed($value + $valueToAdd, false /* to avoid infinite loop */);
                });
        }

        return $speed;
    }

    // NEGATIVE TESTS

    /**
     * @test
     * @expectedException \DrdPlus\FightProperties\Exceptions\CanNotUseArmamentBecauseOfMissingStrength
     * @dataProvider provideArmamentsBearing
     * @param bool $weaponIsBearable
     * @param bool $shieldIsBearable
     * @param bool $armorIsBearable
     * @param bool $helmIsBearable
     */
    public function I_can_not_create_it_with_unbearable_weapon_and_shield(
        $weaponIsBearable,
        $shieldIsBearable,
        $armorIsBearable,
        $helmIsBearable
    )
    {
        $armourer = $this->createArmourer();

        $weaponlikeCode = $this->createWeapon();
        $strengthOfMainHand = Strength::getIt(123);
        $size = Size::getIt(456);
        $this->addCanUseArmament($armourer, $weaponlikeCode, $strengthOfMainHand, $size, $weaponIsBearable);

        $shieldCode = $this->createShieldCode();
        $strengthOfOffhand = Strength::getIt(234);
        $this->addCanUseArmament($armourer, $shieldCode, $strengthOfOffhand, $size, $shieldIsBearable);

        $strength = Strength::getIt(698);
        $bodyArmorCode = BodyArmorCode::getIt(BodyArmorCode::WITHOUT_ARMOR);
        $this->addCanUseArmament($armourer, $bodyArmorCode, $strength, $size, $armorIsBearable);
        $helmCode = HelmCode::getIt(HelmCode::WITHOUT_HELM);
        $this->addCanUseArmament($armourer, $helmCode, $strength, $size, $helmIsBearable);

        new FightProperties(
            $this->createCurrentProperties($strength, $size, $strengthOfMainHand, $strengthOfOffhand),
            $this->createCombatActions($combatActionValues = ['foo']),
            $this->createSkills(),
            $bodyArmorCode,
            $helmCode,
            ProfessionCode::getIt(ProfessionCode::RANGER),
            $this->createTables($weaponlikeCode, $combatActionValues, $armourer),
            $weaponlikeCode,
            $this->createWeaponlikeHolding(
                false, /* does not keep weapon by both hands now */
                true, /* holds weapon by main hand */
                false /* does not hold weapon by offhand */
            ),
            false, // does not fight with two weapons now
            $shieldCode,
            false, // enemy is not faster now
            $this->createGlared()
        );
    }

    public function provideArmamentsBearing(): array
    {
        return [
            [false, true, true, true],
            [true, false, true, true],
            [true, true, false, true],
            [true, true, true, false],
        ];
    }

    /**
     * @test
     * @expectedException \DrdPlus\FightProperties\Exceptions\CanNotHoldItByTwoHands
     * @dataProvider provideWeaponOrShieldInvalidTwoHandsHolding
     * @param bool $fightsWithTwoWeapons
     * @param bool $holdsByTwoHands
     * @param bool $canHoldByOneHand
     */
    public function I_can_not_create_it_with_two_hands_holding_if_not_possible(
        $fightsWithTwoWeapons,
        $holdsByTwoHands,
        $canHoldByOneHand
    )
    {
        $armourer = $this->createArmourer();

        $weaponlikeCode = $this->createWeapon();
        $strengthOfMainHand = Strength::getIt(123);
        $strengthForWeapon = $holdsByTwoHands && $canHoldByOneHand
            ? $strengthOfMainHand->add(2)
            : $strengthOfMainHand;
        $size = Size::getIt(456);
        $this->addCanUseArmament($armourer, $weaponlikeCode, $strengthForWeapon, $size, true, $canHoldByOneHand, !$canHoldByOneHand);

        $shieldCode = ShieldCode::getIt(ShieldCode::WITHOUT_SHIELD);
        $strengthOfOffhand = Strength::getIt(234);
        $this->addCanUseArmament($armourer, $shieldCode, $strengthOfOffhand, $size);

        $strength = Strength::getIt(698);
        $bodyArmorCode = BodyArmorCode::getIt(BodyArmorCode::WITHOUT_ARMOR);
        $this->addCanUseArmament($armourer, $bodyArmorCode, $strength, $size);
        $helmCode = HelmCode::getIt(HelmCode::WITHOUT_HELM);
        $this->addCanUseArmament($armourer, $helmCode, $strength, $size);

        new FightProperties(
            $this->createCurrentProperties($strength, $size, $strengthOfMainHand, $strengthOfOffhand),
            $this->createCombatActions($combatActionValues = ['foo']),
            $this->createSkills(),
            $bodyArmorCode,
            $helmCode,
            ProfessionCode::getIt(ProfessionCode::RANGER),
            $this->createTables($weaponlikeCode, $combatActionValues, $armourer),
            $weaponlikeCode,
            $this->createWeaponlikeHolding(
                $holdsByTwoHands,
                true, /* holds weapon by main hand */
                false /* does not hold weapon by offhand */
            ),
            $fightsWithTwoWeapons,
            $shieldCode,
            false, // enemy is not faster now
            $this->createGlared()
        );
    }

    public function provideWeaponOrShieldInvalidTwoHandsHolding(): array
    {
        return [
            [true, true, false],
            [false, true, true],
        ];
    }

    /**
     * @test
     * @expectedException \DrdPlus\FightProperties\Exceptions\CanNotHoldItByOneHand
     */
    public function I_can_not_create_it_with_one_hand_holding_if_not_possible()
    {
        $armourer = $this->createArmourer();

        $weaponlikeCode = $this->createWeapon();
        $strengthOfMainHand = Strength::getIt(123);
        $size = Size::getIt(456);
        $this->addCanUseArmament($armourer, $weaponlikeCode, $strengthOfMainHand, $size, true, false /* can not hold by one hand */);

        $shieldCode = ShieldCode::getIt(ShieldCode::WITHOUT_SHIELD);
        $strengthOfOffhand = Strength::getIt(234);
        $this->addCanUseArmament($armourer, $shieldCode, $strengthOfOffhand, $size);

        $strength = Strength::getIt(698);
        $bodyArmorCode = BodyArmorCode::getIt(BodyArmorCode::WITHOUT_ARMOR);
        $this->addCanUseArmament($armourer, $bodyArmorCode, $strength, $size);
        $helmCode = HelmCode::getIt(HelmCode::WITHOUT_HELM);
        $this->addCanUseArmament($armourer, $helmCode, $strength, $size);

        new FightProperties(
            $this->createCurrentProperties($strength, $size, $strengthOfMainHand, $strengthOfOffhand),
            $this->createCombatActions($combatActionValues = ['foo']),
            $this->createSkills(),
            $bodyArmorCode,
            $helmCode,
            ProfessionCode::getIt(ProfessionCode::RANGER),
            $this->createTables($weaponlikeCode, $combatActionValues, $armourer),
            $weaponlikeCode,
            $this->createWeaponlikeHolding(
                false, // does not hold it by two hands
                true, /* holds weapon by main hand */
                false /* does not hold weapon by offhand */
            ),
            true, // fights with two weapons (does not affect this test)
            $shieldCode,
            false, // enemy is not faster now
            $this->createGlared()
        );
    }

    /**
     * @test
     * @expectedException \DrdPlus\FightProperties\Exceptions\ImpossibleActionsWithCurrentWeaponlike
     * @expectedExceptionMessageRegExp ~foo~
     */
    public function I_can_not_create_it_with_weapon_incompatible_actions()
    {
        $armourer = $this->createArmourer();

        $weaponlikeCode = $this->createWeapon();
        $strengthOfMainHand = Strength::getIt(123);
        $size = Size::getIt(456);
        $this->addCanUseArmament($armourer, $weaponlikeCode, $strengthOfMainHand, $size);

        $shieldCode = ShieldCode::getIt(ShieldCode::WITHOUT_SHIELD);
        $strengthOfOffhand = Strength::getIt(234);
        $this->addCanUseArmament($armourer, $shieldCode, $strengthOfOffhand, $size);

        $strength = Strength::getIt(698);
        $bodyArmorCode = BodyArmorCode::getIt(BodyArmorCode::WITHOUT_ARMOR);
        $this->addCanUseArmament($armourer, $bodyArmorCode, $strength, $size);
        $helmCode = HelmCode::getIt(HelmCode::WITHOUT_HELM);
        $this->addCanUseArmament($armourer, $helmCode, $strength, $size);

        new FightProperties(
            $this->createCurrentProperties($strength, $size, $strengthOfMainHand, $strengthOfOffhand),
            $this->createCombatActions($combatActionValues = ['foo']),
            $this->createSkills(),
            $bodyArmorCode,
            $helmCode,
            ProfessionCode::getIt(ProfessionCode::RANGER),
            $this->createTables($weaponlikeCode, ['bar'] /* different combat actions possible */, $armourer),
            $weaponlikeCode,
            $this->createWeaponlikeHolding(
                false, // does not hold it by two hands
                true, // holds weapon by main hand
                false /* does not hold weapon by offhand */
            ),
            true, // fights with two weapons (does not affect this test)
            $shieldCode,
            false, // enemy is not faster now (does not affect this test)
            $this->createGlared()
        );
    }

    /**
     * @test
     * @expectedException \DrdPlus\FightProperties\Exceptions\UnknownWeaponHolding
     */
    public function I_can_not_create_it_with_unknown_holding()
    {
        $armourer = $this->createArmourer();

        $weaponlikeCode = $this->createWeapon();
        $strengthOfMainHand = Strength::getIt(123);
        $size = Size::getIt(456);
        $this->addCanUseArmament($armourer, $weaponlikeCode, $strengthOfMainHand, $size);

        $shieldCode = ShieldCode::getIt(ShieldCode::WITHOUT_SHIELD);
        $strengthOfOffhand = Strength::getIt(234);
        $this->addCanUseArmament($armourer, $shieldCode, $strengthOfOffhand, $size);

        $strength = Strength::getIt(698);
        $bodyArmorCode = BodyArmorCode::getIt(BodyArmorCode::WITHOUT_ARMOR);
        $this->addCanUseArmament($armourer, $bodyArmorCode, $strength, $size);
        $helmCode = HelmCode::getIt(HelmCode::WITHOUT_HELM);
        $this->addCanUseArmament($armourer, $helmCode, $strength, $size);

        new FightProperties(
            $this->createCurrentProperties($strength, $size, $strengthOfMainHand, $strengthOfOffhand),
            $this->createCombatActions($combatActionValues = ['foo']),
            $this->createSkills(),
            $bodyArmorCode,
            $helmCode,
            ProfessionCode::getIt(ProfessionCode::RANGER),
            $this->createTables($weaponlikeCode, $combatActionValues, $armourer),
            $weaponlikeCode,
            $this->createWeaponlikeHolding(
                false, // does not hold weapon by two hands
                false, // does not hold weapon by main hand
                false // does not hold weapon by offhand
            ),
            true, // fights with two weapons (does not affect this test)
            $shieldCode,
            false, // enemy is not faster now (does not affect this test)
            $this->createGlared()
        );
    }

    /**
     * @test
     * @expectedException \DrdPlus\FightProperties\Exceptions\NoHandLeftForShield
     * @expectedExceptionMessageRegExp ~buckler when holding foo with~
     */
    public function I_can_not_use_shield_when_holding_weapon_by_two_hands()
    {
        $armourer = $this->createArmourer();

        $weaponlikeCode = $this->createWeapon('foo');
        $strengthOfMainHand = Strength::getIt(123);
        $size = Size::getIt(456);
        $this->addCanUseArmament($armourer, $weaponlikeCode, $strengthOfMainHand, $size, true, true, true, true /* two handed only */);

        $shieldCode = ShieldCode::getIt(ShieldCode::BUCKLER);
        $strengthOfOffhand = Strength::getIt(234);
        $this->addCanUseArmament($armourer, $shieldCode, $strengthOfOffhand, $size);

        $strength = Strength::getIt(698);
        $bodyArmorCode = BodyArmorCode::getIt(BodyArmorCode::WITHOUT_ARMOR);
        $this->addCanUseArmament($armourer, $bodyArmorCode, $strength, $size);
        $helmCode = HelmCode::getIt(HelmCode::WITHOUT_HELM);
        $this->addCanUseArmament($armourer, $helmCode, $strength, $size);

        new FightProperties(
            $this->createCurrentProperties($strength, $size, $strengthOfMainHand, $strengthOfOffhand),
            $this->createCombatActions($combatActionValues = ['bar']),
            $this->createSkills(),
            $bodyArmorCode,
            $helmCode,
            ProfessionCode::getIt(ProfessionCode::RANGER),
            $this->createTables($weaponlikeCode, $combatActionValues, $armourer),
            $weaponlikeCode,
            ItemHoldingCode::getIt(ItemHoldingCode::TWO_HANDS),
            false,
            $shieldCode,
            false, // enemy is not faster now (does not affect this test)
            $this->createGlared()
        );
    }

}