<?php

declare(strict_types=1);

namespace Shimmie2;

trait HasSender
{
    /**
     * @var class-string|null $sender
     */
    public ?string $sender = null;

    /**
     * @param class-string $sender
     */
    public function setSender(string $sender): self
    {
        $this->sender = $sender;
        return $this;
    }
}
