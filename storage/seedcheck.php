<?php

echo 'Roles=' . \Spatie\Permission\Models\Role::count() . PHP_EOL;
echo 'Permissions=' . \Spatie\Permission\Models\Permission::count() . PHP_EOL;

$u = \App\Models\User::where('email', 'admin@asiadentallab.com')->first();
echo 'Admin=' . ($u ? $u->name : 'MISSING') . PHP_EOL;
echo 'AdminRoles=' . ($u ? $u->getRoleNames()->implode(',') : '-') . PHP_EOL;
echo 'SuperAdminPerms=' . \Spatie\Permission\Models\Role::findByName('Super Admin')->permissions->count() . PHP_EOL;
echo 'DoctorPerms=' . \Spatie\Permission\Models\Role::findByName('Doctor')->permissions->pluck('name')->implode(',') . PHP_EOL;
