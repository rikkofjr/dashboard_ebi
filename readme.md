

# Plugin Dashboard EBI for Moodle

**Dashboard EBI** adalah plugin lokal Moodle (`local_dashboard_ebi`) yang dirancang untuk menyediakan portal pembelajaran dan pemantauan pengembangan diri (*Learning & Development Portal*) yang terintegrasi secara komprehensif. 

Plugin ini mendukung visualisasi **Learning Path Individu**, pemantauan **Learning Path Tim (hingga 3 level hirarki)**, manajemen **Individual Development Plan (IDP)**, serta pemantauan **Sertifikasi Eksternal** secara dinamis berdasarkan data Custom Profile Field HR.

---

## 📌 Fitur Utama

1. **Dynamic Role-Based Dashboard Hub:**
    * **Personal View (Karyawan):** Menampilkan matriks course wajib/anjuran berdasarkan level jabatan, nilai akhir, persentase kelulusan (*compliance*), serta chart komposisi kategori tag (Mandatory, Fundamental, dll.).
   * **Leader View (Atasan):** Menampilkan pemantauan matriks pelatihan tim/bawahan secara rekursif hingga 3 level hirarki (*Direct & Sub-Team Report*).
2. **Master-Detail Matrix Mapping (Admin Panel):**
   * Pengaturan kombinasi Tag Moodle (Jabatan + Kategori Tag + Status Tag Access) dengan dukungan *Autocomplete* untuk mencegah kesalahan pengetikan (*typo*).
3. **Multi-Hierarchy Recursive Engine (3-Level Depth Limit):**
   * Penelusuran otomatis struktur atasan-bawahan via Custom Profile Field HR yang aman dari beban *load* database berlebih (*optimized query*).
4. **Dynamic Level Jabatan Filtering:**
   * Filter dropdown interaktif pada tampilan tim yang secara otomatis hanya menampilkan level jabatan unik milik bawahan di bawah atasan terkait.
5. **Universal Portability & Integration Settings:**
   * Konfigurasi dinamis untuk pengenalan nama field profile HR (Jabatan, Atasan Langsung) serta kunci identitas atasan (Username, Email, NIK/ID Number).

---

## 🛠️ Panduan untuk LMS Administrator

### 1. Persyaratan Sistem
* Moodle 4.0 atau yang lebih baru.
* Theme berbasis Bootstrap 4/5 (Boost, Classic, atau turunan bawaan Moodle).
* PHP versi 7.4 / 8.0 / 8.1+.

### 2. Struktur Directory Plugin
Pastikan plugin diletakkan di dalam folder `local` Moodle Anda:

```text
moodle/
└── local/
    └── dashboard_ebi/
        ├── admin/
        │   └── matrix_admin_panel.php   # Panel Admin Master Matriks Tag
        ├── db/
        │   ├── install.xml              # Schema Tabel Database
        │   └── upgrade.php              # Continuous Development Script
        ├── lang/
        │   └── en/
        │       └── local_dashboard_ebi.php # String Bahasa Resmi
        ├── views/
        │   ├── tab_my_learning_path.php   # View Dashboard Karyawan
        │   ├── tab_team_learning_path.php # View Pemantauan Tim
        │   ├── tab_my_idp.php             # View IDP Individu
        │   └── tab_team_idp.php           # View IDP & Approval Tim
        ├── index.php                     # Controller Utama & Tab Wrapper
        ├── settings.php                  # Panel Pengaturan Plugin
        ├── version.php                   # Manifest Versi Plugin
        └── README.md                     # Dokumentasi Plugin

```
### 3. Langkah Instalasi
1. Download .zip folder repository ini 
2. Upload .zip tersebut pada **Admin > Plugins > Install Plugin**
3. Lakukan proses instalasi hingga berhasil

### 4. Konfigurasi
dikarenakan dashboard ini mendeteksi level jabatan user (ex : manager, staff) dan mendeteksi siapa atasannya maka perlu ada penyesuaian sesuai dengan table anda 
1. Buka Site Administration > Plugins > Local plugins > Dashboard EBI.
2. Atur parameter berikut:
    * **Profile Field Level Jabatan**: Isikan shortname field profil yang menyimpan data Jabatan Karyawan (Default: level_jabatan).  
    * **Profile Field Atasan Langsung**: Isikan shortname field profil yang menyimpan data Atasan Karyawan (Default: atasan_langsung).  
    * **Identitas Kunci Atasan**: Pilih jenis data yang diisikan bawahan untuk merujuk pada atasannya (Username, Email Address, atau ID Number/NIK).

### 5. Mengatur Matriks Tag Pelatihan 
Sebelum anda memetakan matriks tag, psatikan anda telah memberikan TAG yang sesuai pada course

1. Akses menu Admin Matriks via URL: ``` /local/dashboard_ebi/admin/matrix_admin_panel.php.```  
2. Master Level Jabatan: Masukkan atau pilih Tag Moodle untuk Level Jabatan (misal: supervisor). **Pastikan course tersebut telah memiliki tag tersebut** 
3. Klik **Kelola Rule Tag**
4. Tambahkan Aturan kombinasi Tag Course, anda bisa menggunakan contoh ini
    **Tag Kategori Course**: Masukkan Tag Moodle untuk kategori (misal: mandatory, fundamental).  
    **Tag Status Access Course**: Pilih status sifat course (open atau closed).

## 🛠️ Panduan untuk end user / karyawan / peserta training

### 1. Akses Dashboard
1. Akses halaman dashboard EBI pada URL : ```/local/dashboard_ebi/index.php```
2. Anda akan melihat course yang harus anda penuhi di level anda saat ini
