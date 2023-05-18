<?php
// CakeFuzzerInstrumentation:fileDelete

namespace Authentication\Identifier;
use Authentication\Identifier\Resolver\ResolverInterface;

class FakeIdentifier extends PasswordIdentifier {
    public function identify($notused=[])
    {
        return $this->getResolver()->find([], ResolverInterface::TYPE_OR);
    }
}