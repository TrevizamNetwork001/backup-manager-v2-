<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin {--bootstrap : Cria o acesso inicial de instalação nova}';

    protected $description = 'Cria ou atualiza um usuário administrador';

    public function handle(): int
    {
        if ($this->option('bootstrap')) {
            $existing = User::query()->limit(2)->get();
            $bootstrap = $existing->first(fn (User $user): bool => $user->name === 'backup_manager' &&
                $user->email === 'backup_manager@local.invalid' &&
                $user->must_complete_setup
            );
            if ($existing->isNotEmpty() && ($existing->count() !== 1 || $bootstrap === null)) {
                $this->error('O acesso inicial só pode ser criado ou renovado antes da configuração do primeiro usuário.');

                return self::FAILURE;
            }

            $password = bin2hex(random_bytes(24));
            $attributes = [
                'name' => 'backup_manager',
                'email' => 'backup_manager@local.invalid',
                'password' => $password,
                'is_admin' => true,
                'role' => Rbac::ROLE_ADMIN,
                'is_active' => true,
                'must_complete_setup' => true,
            ];
            if ($existing->isEmpty()) {
                User::create($attributes);
            } else {
                $bootstrap->update($attributes);
            }

            $this->line('Senha inicial: '.$password);

            return self::SUCCESS;
        }

        $name = trim((string) $this->ask('Nome'));
        $email = strtolower(trim((string) $this->ask('E-mail')));
        $password = (string) $this->secret('Senha');
        $confirmation = (string) $this->secret('Confirme a senha');

        if ($password !== $confirmation) {
            $this->error('As senhas não conferem.');

            return self::FAILURE;
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [
                'required',
                Password::min(12)
                    ->letters()
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'is_admin' => true,
                'role' => Rbac::ROLE_ADMIN,
                'is_active' => true,
            ]
        );

        $this->info("Administrador configurado: {$user->email}");

        return self::SUCCESS;
    }
}
