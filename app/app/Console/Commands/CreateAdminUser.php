<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdminUser extends Command
{
    protected $signature = 'app:create-admin';

    protected $description = 'Cria ou atualiza um usuário administrador';

    public function handle(): int
    {
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
