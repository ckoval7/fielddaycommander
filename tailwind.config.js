/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './vendor/robsontenorio/mary/src/View/Components/**/*.php',
    ],

    plugins: [
        require('daisyui'),
    ],

    daisyui: {
        themes: ['light', 'dark'],
    },
};
