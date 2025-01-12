<?php

declare(strict_types=1);

namespace Larastan\Larastan\Types;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
use PHPStan\Type\Type;

use function count;
use function in_array;

class RelationDynamicMethodReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(private ReflectionProvider $provider)
    {
    }

    public function getClass(): string
    {
        return Model::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return in_array($methodReflection->getName(), [
            'hasOne',
            'hasOneThrough',
            'morphOne',
            'belongsTo',
            'morphTo',
            'hasMany',
            'hasManyThrough',
            'morphMany',
            'belongsToMany',
            'morphToMany',
            'morphedByMany',
        ], true);
    }

    /** @throws ShouldNotHappenException */
    public function getTypeFromMethodCall(
        MethodReflection $methodReflection,
        MethodCall $methodCall,
        Scope $scope,
    ): Type|null {
        /** @var FunctionVariant $functionVariant */
        $functionVariant = ParametersAcceptorSelector::selectFromArgs($scope, $methodCall->getArgs(), $methodReflection->getVariants());
        $returnType      = $functionVariant->getReturnType();

        $classNames = $returnType->getObjectClassNames();

        if (count($classNames) !== 1) {
            return null;
        }

        $calledOnType = $scope->getType($methodCall->var);

        if ($calledOnType instanceof StaticType) {
            $calledOnType = new ObjectType($calledOnType->getClassName());
        }

        if (count($methodCall->getArgs()) === 0) {
            // Special case for MorphTo. `morphTo` can be called without arguments.
            if ($methodReflection->getName() === 'morphTo') {
                return new GenericObjectType($classNames[0], [new ObjectType(Model::class), $calledOnType]);
            }

            return null;
        }

        $argType    = $scope->getType($methodCall->getArgs()[0]->value);
        $argStrings = $argType->getConstantStrings();

        if (count($argStrings) !== 1) {
            return null;
        }

        $argClassName = $argStrings[0]->getValue();

        if (! $this->provider->hasClass($argClassName)) {
            $argClassName = Model::class;
        }

        // Special case for BelongsTo. We need to add the child model as a generic type also.
        if ((new ObjectType(BelongsTo::class))->isSuperTypeOf($returnType)->yes()) {
            return new GenericObjectType($classNames[0], [new ObjectType($argClassName), $calledOnType]);
        }

        return new GenericObjectType($classNames[0], [new ObjectType($argClassName)]);
    }
}
