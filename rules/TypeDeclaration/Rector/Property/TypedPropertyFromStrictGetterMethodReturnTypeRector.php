<?php

declare(strict_types=1);

namespace Rector\TypeDeclaration\Rector\Property;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PHPStan\Type\MixedType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfoFactory;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\Reflection\ReflectionResolver;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\DeadCode\PhpDoc\TagRemover\VarTagRemover;
use Rector\PHPStanStaticTypeMapper\Enum\TypeKind;
use Rector\Privatization\Guard\ParentPropertyLookupGuard;
use Rector\TypeDeclaration\AlreadyAssignDetector\ConstructorAssignDetector;
use Rector\TypeDeclaration\TypeInferer\PropertyTypeInferer\GetterTypeDeclarationPropertyTypeInferer;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * @see \Rector\Tests\TypeDeclaration\Rector\Property\TypedPropertyFromStrictGetterMethodReturnTypeRector\TypedPropertyFromStrictGetterMethodReturnTypeRectorTest
 */
final class TypedPropertyFromStrictGetterMethodReturnTypeRector extends AbstractRector implements MinPhpVersionInterface
{
    public function __construct(
        private readonly GetterTypeDeclarationPropertyTypeInferer $getterTypeDeclarationPropertyTypeInferer,
        private readonly VarTagRemover $varTagRemover,
        private readonly ParentPropertyLookupGuard $parentPropertyLookupGuard,
        private readonly ReflectionResolver $reflectionResolver,
        private readonly ConstructorAssignDetector $constructorAssignDetector,
        private readonly PhpDocInfoFactory $phpDocInfoFactory
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Complete property type based on getter strict types',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
final class SomeClass
{
    public $name;

    public function getName(): string|null
    {
        return $this->name;
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
final class SomeClass
{
    public ?string $name = null;

    public function getName(): string|null
    {
        return $this->name;
    }
}
CODE_SAMPLE
                ), ]
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): null|Class_
    {
        $hasChanged = false;

        foreach ($node->getProperties() as $property) {
            if ($this->shouldSkipProperty($property, $node)) {
                continue;
            }

            $getterReturnType = $this->getterTypeDeclarationPropertyTypeInferer->inferProperty($property, $node);
            if (! $getterReturnType instanceof Type) {
                continue;
            }

            if ($getterReturnType instanceof MixedType) {
                continue;
            }

            $isAssignedInConstructor = $this->constructorAssignDetector->isPropertyAssigned(
                $node,
                $this->getName($property)
            );

            // if property is public, it should be nullable
            if ($property->isPublic() && ! TypeCombinator::containsNull(
                $getterReturnType
            ) && ! $isAssignedInConstructor) {
                $getterReturnType = TypeCombinator::addNull($getterReturnType);
            }

            $propertyTypeNode = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode(
                $getterReturnType,
                TypeKind::PROPERTY
            );
            if (! $propertyTypeNode instanceof Node) {
                continue;
            }

            // include fault value in the type
            if ($this->isConflictingDefaultExprType($property, $getterReturnType)) {
                continue;
            }

            $property->type = $propertyTypeNode;
            $this->decorateDefaultExpr($getterReturnType, $property, $isAssignedInConstructor);

            $this->refactorPhpDoc($property);

            $hasChanged = true;
        }

        if ($hasChanged) {
            return $node;
        }

        return null;
    }

    public function provideMinPhpVersion(): int
    {
        return PhpVersionFeature::TYPED_PROPERTIES;
    }

    private function decorateDefaultExpr(Type $propertyType, Property $property, bool $isAssignedInConstructor): void
    {
        if ($isAssignedInConstructor) {
            return;
        }

        $propertyProperty = $property->props[0];

        // already has a default value
        if ($propertyProperty->default instanceof Expr) {
            return;
        }

        if (TypeCombinator::containsNull($propertyType)) {
            $propertyProperty->default = $this->nodeFactory->createNull();
            return;
        }

        // set default for string
        if ($propertyType instanceof StringType) {
            $propertyProperty->default = new String_('');
        }
    }

    private function isConflictingDefaultExprType(Property $property, Type $getterReturnType): bool
    {
        $onlyPropertyProperty = $property->props[0];
        if (! $onlyPropertyProperty->default instanceof Expr) {
            return false;
        }

        $defaultType = $this->staticTypeMapper->mapPhpParserNodePHPStanType($onlyPropertyProperty->default);

        // does default type match the getter one?
        return ! $defaultType->isSuperTypeOf($getterReturnType)
            ->yes();
    }

    private function refactorPhpDoc(Property $property): void
    {
        $phpDocInfo = $this->phpDocInfoFactory->createFromNode($property);
        if (! $phpDocInfo instanceof PhpDocInfo) {
            return;
        }

        $this->varTagRemover->removeVarTagIfUseless($phpDocInfo, $property);
    }

    private function shouldSkipProperty(Property $property, Class_ $class): bool
    {
        if ($property->type instanceof Node) {
            // already has type
            return true;
        }

        // skip non-single property
        if (count($property->props) !== 1) {
            // has too many properties
            return true;
        }

        $classReflection = $this->reflectionResolver->resolveClassReflection($class);
        return ! $this->parentPropertyLookupGuard->isLegal($property, $classReflection);
    }
}
