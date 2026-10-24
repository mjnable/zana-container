<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Where a constructor argument comes from (container specification §6.2).
 *
 * @internal
 */
enum ParameterSource
{
    /** The owning resolver itself (PSR or Zana resolver type). */
    case Resolver;

    /** get() of a registered service. */
    case Service;

    /** getAll() spread into a variadic parameter. */
    case Variadic;

    /** The instance being decorated (decorator constructors only). */
    case Inner;

    /** The parameter's declared default value. */
    case Default;

    /** null, for a nullable parameter. */
    case Null;
}
