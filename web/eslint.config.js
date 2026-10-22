// Konfigurasi ESLint untuk SPA Vue.
//
// Sengaja TIDAK memakai preset gaya (`plugin:vue/recommended`, aturan spasi,
// urutan atribut). Repo ini sudah punya gaya yang konsisten dan tidak
// ditegakkan mesin; menambahkan preset di akhir proyek berarti diff ratusan
// baris yang menutupi perubahan nyata.
//
// Yang ditegakkan hanya kesalahan yang tidak terlihat saat dibaca: variabel
// yang tidak pernah dipakai, `v-for` tanpa `:key`, akses `ref` yang lupa
// `.value`, dan komponen yang dipakai tetapi tidak diimpor. Semuanya lolos
// `vite build` tanpa keluhan dan baru ketahuan saat halamannya dibuka.

import js from '@eslint/js'
import pluginVue from 'eslint-plugin-vue'
import globals from 'globals'

export default [
  {
    ignores: ['dist/**', 'node_modules/**'],
  },

  js.configs.recommended,
  ...pluginVue.configs['flat/essential'],

  {
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: {
        ...globals.browser,
      },
    },
    rules: {
      // Variabel dan argumen yang tidak dipakai. Argumen yang diawali `_`
      // dikecualikan karena itu penanda "sengaja tidak dipakai".
      'no-unused-vars': ['error', { argsIgnorePattern: '^_', varsIgnorePattern: '^_' }],

      // `v-for` tanpa `:key` membuat Vue memakai ulang elemen secara keliru
      // saat daftar berubah — gejalanya baris yang isinya tertukar.
      'vue/require-v-for-key': 'error',

      // Komponen yang dipakai di template tetapi tidak diimpor akan tampil
      // sebagai elemen HTML kosong, tanpa galat apa pun.
      'vue/no-undef-components': 'error',

      // Memakai nama komponen yang bentrok dengan elemen HTML.
      'vue/no-reserved-component-names': 'error',

      // `v-if` dan `v-for` pada elemen yang sama: `v-if` dievaluasi lebih dulu
      // dan tidak bisa melihat variabel dari `v-for`.
      'vue/no-use-v-if-with-v-for': 'error',

      // Deklarasi ulang properti di template.
      'vue/no-duplicate-attributes': 'error',

      // Sintaks template yang tidak sah.
      'vue/valid-v-for': 'error',
      'vue/valid-v-if': 'error',
      'vue/valid-v-model': 'error',
    },
  },

  {
    // Berkas konfigurasi berjalan di Node, bukan di peramban.
    files: ['*.config.js', 'vite.config.js'],
    languageOptions: {
      globals: { ...globals.node },
    },
  },
]
