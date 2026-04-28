<?php

declare(strict_types=1);

namespace Aegis\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Aegis\Strategy\Retry\RetryOptions;

/**
 * @implements Rule<New_>
 *
 * Ensures every class in RetryOptions::$retryOn is a Throwable subclass.
 */
final class RetryOnThrowableRule implements Rule
{
    public function getNodeType(): string
    {
        return New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        assert($node instanceof New_);

        if (!$node->class instanceof Name) {
            return [];
        }

        $className = $scope->resolveName($node->class);
        if ($className !== RetryOptions::class) {
            return [];
        }

        $errors = [];

        // retryOn is the 3rd positional argument (index 2), or named
        foreach ($node->args as $index => $arg) {
            $argName = $arg->name?->toString();
            if ($argName !== 'retryOn' && $index !== 2) {
                continue;
            }

            if (!$arg->value instanceof Node\Expr\Array_) {
                break;
            }

            foreach ($arg->value->items as $item) {
                if (!$item?->value instanceof Node\Expr\ClassConstFetch) {
                    continue;
                }

                $fetch = $item->value;
                if (!$fetch->class instanceof Name || $fetch->name->toString() !== 'class') {
                    continue;
                }

                $fqcn = $scope->resolveName($fetch->class);
                if (!is_a($fqcn, \Throwable::class, true)) {
                    $errors[] = RuleErrorBuilder::message(
                        sprintf('RetryOptions::$retryOn: "%s" does not implement Throwable.', $fqcn)
                    )->build();
                }
            }
            break;
        }

        return $errors;
    }
}
