<?php

declare(strict_types=1);

namespace Zana\Container\Internal;

/**
 * Where an argument or injected property value comes from (specification §6.2).
 *
 * @internal
 */
enum ParameterSource
{
    /** The owning resolver itself (PSR or Zana resolver type). */
    case Resolver;

    /** get() of a registered service. */
    case Service;

    /** getKeyed() of a keyed registration (#[Inject] with a key). */
    case Keyed;

    /** tagged() of a tag (#[Tagged]). */
    case Tagged;

    /** A parameter value provider (#[Env], #[Config], ...). */
    case Provider;

    /** The owning resolver's ScopeMetadata. */
    case Metadata;

    /** getAll() spread into a variadic parameter. */
    case Variadic;

    /** The instance being decorated (decorator constructors only). */
    case Inner;

    /** A named argument passed to call(). */
    case Override;

    /** The declared default value (properties keep theirs untouched). */
    case Default;

    /** null, for a nullable parameter or property. */
    case Null;
}
