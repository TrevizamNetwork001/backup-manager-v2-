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
            if (User::query()->exists()) {
                $this->error('O acesso inicial só pode ser criado em um banco sem usuários.');

                return self::FAILURE;
            }

            User::create([
                'name' => 'backup_manager',
                'email' => 'backup_manager@local.invalid',
                'password' => 'backup_manager',
                'is_admin' => true,
                'role' => Rbac::ROLE_ADMIN,
                'is_active' => true,
                'must_complete_setup' => true,
            ]);

            $this->info('Acesso inicial criado. Usuário: backup_manager; senha: backup_manager. Troque os dados no primeiro login.');

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
            ]
        );

        $this->info("Administrador configurado: {$user->email}");

        return self::SUCCESS;
    }
}
