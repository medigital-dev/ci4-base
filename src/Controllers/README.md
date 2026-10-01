# Controllers

## BaseApiController

`BaseApiController` menyediakan helper controller API untuk inisialisasi model, permission/group guard, validasi request, dan response JSON.

### Integrasi ke project CI4

File source saat ini dideklarasikan dengan namespace `App\Controllers` dan mewarisi `BaseController` milik aplikasi. Namespace ini bukan bagian dari mapping Composer package (`MedigitalDev\Ci4Base\`). Salin/adaptasikan `BaseApiController.php` ke `app/Controllers/BaseApiController.php` pada project CI4 yang memakai library ini. Sesuaikan namespace dan parent class jika struktur aplikasi berbeda.

Class ini menggunakan CodeIgniter Shield untuk `auth()->user()`, pemeriksaan permission `can()`, dan group `inGroup()`.

### Model utama

Atur `$modelName` pada controller turunan untuk membuat model otomatis. Prefix permission diturunkan dari nama class model: `UserModel` menjadi `user`. Atur `$permissionPrefix` secara eksplisit bila permission menggunakan nama lain.

```php
<?php

namespace App\Controllers;

use App\Models\UserModel;

class Users extends BaseApiController
{
    protected string $modelName = UserModel::class;
    protected string $permissionPrefix = 'users';

    public function index()
    {
        if ($fail = $this->cant($this->permissionPrefix . '.index')) {
            return $this->respondGuard($fail, 403);
        }

        return $this->respondSuccess('Daftar user berhasil diambil.', $this->model->findAll());
    }
}
```

`$modelName` harus berupa class turunan `MedigitalDev\Ci4Base\Models\BaseModel`. Jika tidak diisi, `$this->model` tetap `null`. Untuk menggunakan model lain, panggil `$this->useModel(OtherModel::class)` dari controller turunan.

### Guard dan response

```php
if ($fail = $this->notValid([
    'name'  => 'required',
    'email' => 'required|valid_email',
])) {
    return $this->respondGuard($fail, 422);
}

return $this->respondSuccess('Data valid.');
```

- `cant($permission, $message, $errors)`: gagal jika pengguna belum login atau tidak memiliki permission.
- `notIn($group, $message, $errors)`: gagal jika pengguna tidak termasuk group.
- `notValid($rules, $message)`: menjalankan validasi CI4 dan menyertakan error validator pada payload.
- `respondSuccess($message, $data, $code)`: mengirim payload sukses; default HTTP 200.
- `respondError($message, $errors, $code)`: mengirim payload error; default HTTP 400.
- `respondGuard($payload, $code)`: mengirim payload yang dikembalikan guard dengan status code yang dipilih controller.
- `success(...)` dan `error(...)`: menyusun payload tanpa langsung mengirim response.

Guard mengembalikan `null` jika lolos, atau payload error jika gagal. Controller bertanggung jawab menentukan status HTTP ketika memanggil `respondGuard()`.
