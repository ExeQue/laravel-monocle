<?php

declare(strict_types=1);

namespace Tests\Fixtures\Livewire;

use Livewire\Component;

final class LoginForm extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(string $password, string $email): void
    {
        $this->password = $password;
        $this->email = $email;
    }

    public function render(): string
    {
        return '<div>{{ $email }}</div>';
    }
}
