<?php
namespace DrdPlus\FightProperties;

use DrdPlus\Properties\Base\Strength;
use DrdPlus\Properties\Body\Height;
use DrdPlus\Properties\Body\Size;
use DrdPlus\Properties\Combat\BaseProperties;
use DrdPlus\Properties\Derived\Speed;

interface CurrentProperties extends BaseProperties
{

    /**
     * @return Strength
     */
    public function getStrengthForMainHandOnly(): Strength;

    /**
     * @return Strength
     */
    public function getStrengthForOffhandOnly(): Strength;

    /**
     * Bonus of height in fact - usable for Fight and Speed
     *
     * @return Height
     */
    public function getHeight(): Height;

    /**
     * @return Size
     */
    public function getSize(): Size;

    /**
     * @return Speed
     */
    public function getSpeed(): Speed;
}