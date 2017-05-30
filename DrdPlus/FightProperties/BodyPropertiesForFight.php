<?php
namespace DrdPlus\FightProperties;

use DrdPlus\Properties\Base\Agility;
use DrdPlus\Properties\Base\Charisma;
use DrdPlus\Properties\Base\Intelligence;
use DrdPlus\Properties\Base\Knack;
use DrdPlus\Properties\Base\Strength;
use DrdPlus\Properties\Base\Will;
use DrdPlus\Properties\Body\Height;
use DrdPlus\Properties\Body\Size;
use DrdPlus\Properties\Combat\BaseProperties;
use DrdPlus\Properties\Derived\Speed;
use Granam\Strict\Object\StrictObject;

class BodyPropertiesForFight extends StrictObject implements BaseProperties
{
    /**
     * @var Strength
     */
    private $strength;
    /**
     * @var Strength
     */
    private $strengthOfOffhand;
    /**
     * @var Agility
     */
    private $agility;
    /**
     * @var Knack
     */
    private $knack;
    /**
     * @var Will
     */
    private $will;
    /**
     * @var Intelligence
     */
    private $intelligence;
    /**
     * @var Charisma
     */
    private $charisma;
    /**
     * @var Size
     */
    private $size;
    /**
     * @var Height
     */
    private $height;
    /**
     * @var Speed
     */
    private $speed;

    public function __construct(
        Strength $strength,
        Agility $agility,
        Knack $knack,
        Will $will,
        Intelligence $intelligence,
        Charisma $charisma,
        Size $size,
        Height $height,
        Speed $speed
    )
    {
        $this->strength = $strength;
        $this->agility = $agility;
        $this->knack = $knack;
        $this->will = $will;
        $this->intelligence = $intelligence;
        $this->charisma = $charisma;
        $this->size = $size;
        $this->height = $height;
        $this->speed = $speed;
    }

    /**
     * @return Strength
     */
    public function getStrength(): Strength
    {
        return $this->strength;
    }

    /**
     * @return Strength
     */
    public function getStrengthOfMainHand(): Strength
    {
        return $this->getStrength();
    }

    /**
     * @return Strength
     */
    public function getStrengthOfOffhand(): Strength
    {
        if ($this->strengthOfOffhand === null) {
            /** @noinspection ExceptionsAnnotatingAndHandlingInspection */
            $this->strengthOfOffhand = $this->getStrength()->sub(2); // offhand has a malus to strength (try to carry you purchase in offhand sometimes...)
        }

        return $this->strengthOfOffhand;
    }

    /**
     * @return Agility
     */
    public function getAgility(): Agility
    {
        return $this->agility;
    }

    /**
     * @return Knack
     */
    public function getKnack(): Knack
    {
        return $this->knack;
    }

    /**
     * @return Will
     */
    public function getWill(): Will
    {
        return $this->will;
    }

    /**
     * @return Intelligence
     */
    public function getIntelligence(): Intelligence
    {
        return $this->intelligence;
    }

    /**
     * @return Charisma
     */
    public function getCharisma(): Charisma
    {
        return $this->charisma;
    }

    /**
     * @return Size
     */
    public function getSize(): Size
    {
        return $this->size;
    }

    /**
     * @return Height
     */
    public function getHeight(): Height
    {
        return $this->height;
    }

    /**
     * @return Speed
     */
    public function getSpeed(): Speed
    {
        return $this->speed;
    }

}