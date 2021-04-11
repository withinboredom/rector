<?php

declare(strict_types=1);

namespace Rector\DowngradePhp72\NodeAnalyzer;

use PhpParser\Node;
use PhpParser\Node\Param;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;
use PHPStan\Type\TypehintHelper;
use Rector\StaticTypeMapper\StaticTypeMapper;
use Symplify\PackageBuilder\Reflection\PrivatesAccessor;

final class NativeTypeClassTreeResolver
{
    /**
     * @var StaticTypeMapper
     */
    private $staticTypeMapper;

    /**
     * @var PrivatesAccessor
     */
    private $privatesAccessor;

    public function __construct(StaticTypeMapper $staticTypeMapper, PrivatesAccessor $privatesAccessor)
    {
        $this->staticTypeMapper = $staticTypeMapper;
        $this->privatesAccessor = $privatesAccessor;
    }

    public function resolveParameterReflectionType(
        ClassReflection $classReflection,
        string $methodName,
        int $position
    ): Type {
        $nativeReflectionClass = $classReflection->getNativeReflection();

        $reflectionMethod = $nativeReflectionClass->getMethod($methodName);
        $parameterReflection = $reflectionMethod->getParameters()[$position] ?? null;
        if (! $parameterReflection instanceof \ReflectionParameter) {
            return new MixedType();
        }

        // "native" reflection from PHPStan removes the type, so we need to check with both reflection and php-paser
        $nativeType = $this->resolveNativeType($parameterReflection);
        if (! $nativeType instanceof MixedType) {
            return $nativeType;
        }

        return TypehintHelper::decideTypeFromReflection(
            $parameterReflection->getType(),
            null,
            $classReflection->getName(),
            $parameterReflection->isVariadic()
        );
    }

    private function resolveNativeType(\ReflectionParameter $reflectionParameter): Type
    {
        $betterReflectionParameter = $this->privatesAccessor->getPrivateProperty(
            $reflectionParameter,
            'betterReflectionParameter'
        );

        $param = $this->privatesAccessor->getPrivateProperty($betterReflectionParameter, 'node');
        if (! $param instanceof Param) {
            return new MixedType();
        }

        if (! $param->type instanceof Node) {
            return new MixedType();
        }

        return $this->staticTypeMapper->mapPhpParserNodePHPStanType($param->type);
    }
}
