<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ config('app.name', 'Tokenpap') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    <!-- Styles / Scripts -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            /*! tailwindcss v4.0.7 | MIT License | https://tailwindcss.com */
            @layer theme {

                :root,
                :host {
                    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", "Noto Color Emoji";
                    --color-red-500: #F53003;
                    --color-red-600: #F61500;
                }
            }

            /* ... (keep all the existing Tailwind CSS styles) ... */
        </style>
    @endif
    <base target="_blank">
</head>

<body
    class="bg-[#FDFDFC] dark:bg-[#0a0a0a] text-[#1b1b18] flex p-6 lg:p-8 items-center lg:justify-center min-h-screen flex-col">
    <header class="w-full lg:max-w-4xl max-w-[335px] text-sm mb-6 not-has-[nav]:hidden">
        @if (Route::has('login'))
            <nav class="flex items-center justify-end gap-4">
                @auth
                    <a href="{{ url('/dashboard') }}"
                        class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal">
                        Dashboard
                    </a>
                @else
                    <a href="{{ route('login') }}"
                        class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] text-[#1b1b18] border border-transparent hover:border-[#19140035] dark:hover:border-[#3E3E3A] rounded-sm text-sm leading-normal">
                        Log in
                    </a>
                    @if (Route::has('register'))
                        <a href="{{ route('register') }}"
                            class="inline-block px-5 py-1.5 dark:text-[#EDEDEC] border-[#19140035] hover:border-[#1915014a] border text-[#1b1b18] dark:border-[#3E3E3A] dark:hover:border-[#62605b] rounded-sm text-sm leading-normal">
                            Register
                        </a>
                    @endif
                @endauth
            </nav>
        @endif
    </header>

    <div
        class="flex items-center justify-center w-full transition-opacity opacity-100 duration-750 lg:grow starting:opacity-0">
        <main class="flex max-w-[335px] w-full flex-col-reverse lg:max-w-4xl lg:flex-row">
            <div
                class="text-[13px] leading-[20px] flex-1 p-6 pb-12 lg:p-20 bg-white dark:bg-[#161615] dark:text-[#EDEDEC] shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d] rounded-bl-lg rounded-br-lg lg:rounded-tl-lg lg:rounded-br-none">
                <h1 class="mb-1 font-medium">Let's get started</h1>
                <p class="mb-2 text-[#706f6c] dark:text-[#A1A09A]">Tokenpap has an incredibly rich ecosystem. <br>We
                    suggest starting with the following.</p>
                <ul class="flex flex-col mb-4 lg:mb-6">
                    <li
                        class="flex items-center gap-4 py-2 relative before:border-l before:border-[#e3e3e0] dark:before:border-[#3E3E3A] before:top-1/2 before:bottom-0 before:left-[0.4rem] before:absolute">
                        <span class="relative py-1 bg-white dark:bg-[#161615]">
                            <span
                                class="flex items-center justify-center rounded-full bg-[#FDFDFC] dark:bg-[#161615] shadow-[0px_0px_1px_0px_rgba(0,0,0,0.03),0px_1px_2px_0px_rgba(0,0,0,0.06)] w-3.5 h-3.5 border dark:border-[#3E3E3A] border-[#e3e3e0]">
                                <span class="rounded-full bg-[#dbdbd7] dark:bg-[#3E3E3A] w-1.5 h-1.5"></span>
                            </span>
                        </span>
                        <span>
                            Read the
                            <a href="https://tokenpap.com/docs" target="_blank"
                                class="inline-flex items-center space-x-1 font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433] ml-1">
                                <span>Documentation</span>
                                <svg width="10" height="11" viewBox="0 0 10 11" fill="none"
                                    xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5">
                                    <path d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001" stroke="currentColor"
                                        stroke-linecap="square" />
                                </svg>
                            </a>
                        </span>
                    </li>
                    <li
                        class="flex items-center gap-4 py-2 relative before:border-l before:border-[#e3e3e0] dark:before:border-[#3E3E3A] before:bottom-1/2 before:top-0 before:left-[0.4rem] before:absolute">
                        <span class="relative py-1 bg-white dark:bg-[#161615]">
                            <span
                                class="flex items-center justify-center rounded-full bg-[#FDFDFC] dark:bg-[#161615] shadow-[0px_0px_1px_0px_rgba(0,0,0,0.03),0px_1px_2px_0px_rgba(0,0,0,0.06)] w-3.5 h-3.5 border dark:border-[#3E3E3A] border-[#e3e3e0]">
                                <span class="rounded-full bg-[#dbdbd7] dark:bg-[#3E3E3A] w-1.5 h-1.5"></span>
                            </span>
                        </span>
                        <span>
                            Watch video tutorials at
                            <a href="https://laracasts.com" target="_blank"
                                class="inline-flex items-center space-x-1 font-medium underline underline-offset-4 text-[#f53003] dark:text-[#FF4433] ml-1">
                                <span>Laracasts</span>
                                <svg width="10" height="11" viewBox="0 0 10 11" fill="none"
                                    xmlns="http://www.w3.org/2000/svg" class="w-2.5 h-2.5">
                                    <path d="M7.70833 6.95834V2.79167H3.54167M2.5 8L7.5 3.00001" stroke="currentColor"
                                        stroke-linecap="square" />
                                </svg>
                            </a>
                        </span>
                    </li>
                </ul>
                <ul class="flex gap-3 text-sm leading-normal">
                    <li>
                        <a href="https://tokenpap.com" target="_blank"
                            class="inline-block dark:bg-[#eeeeec] dark:border-[#eeeeec] dark:text-[#1C1C1A] dark:hover:bg-white dark:hover:border-white hover:bg-black hover:border-black px-5 py-1.5 bg-[#1b1b18] rounded-sm border border-black text-white text-sm leading-normal">
                            Explore it now
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Right side with Tokenpap branding -->
            <div
                class="bg-[#fff2f2] dark:bg-[#1D0002] relative lg:-ml-px -mb-px lg:mb-0 rounded-t-lg lg:rounded-t-none lg:rounded-r-lg aspect-[335/376] lg:aspect-auto w-full lg:w-[438px] shrink-0 overflow-hidden flex items-start justify-center pt-8">

                <!-- Tokenpap Text Logo -->
                <div class="text-[#F53003] dark:text-[#F61500] text-6xl lg:text-7xl font-bold tracking-tight transition-all translate-y-0 opacity-100 duration-750 starting:opacity-0 starting:translate-y-6"
                    style="font-family: 'Instrument Sans', sans-serif;">
                    Tokenpap
                </div>

                <!-- Abstract geometric illustration (simplified version) -->
                <svg class="absolute bottom-0 w-full h-auto opacity-80" viewBox="0 0 440 300" fill="none"
                    xmlns="http://www.w3.org/2000/svg">
                    <g
                        class="transition-all delay-300 translate-y-0 opacity-100 duration-750 starting:opacity-0 starting:translate-y-4">
                        <path
                            d="M188.263 255.73L188.595 255.73C195.441 248.845 205.766 239.761 219.569 228.477C232.93 217.193 242.978 208.205 249.714 201.511C256.34 194.626 260.867 187.358 263.296 179.708C265.725 172.058 264.565 164.121 259.816 155.896C254.516 146.716 247.062 139.352 237.454 133.805C227.957 128.067 217.908 125.198 207.307 125.198C196.927 125.197 190.136 127.97 186.934 133.516C183.621 138.872 184.726 146.331 190.247 155.894L125.647 155.891C116.371 139.825 112.395 125.481 113.72 112.858C115.265 100.235 121.559 90.481 132.602 83.596C143.754 76.52 158.607 72.982 177.159 72.983C196.594 72.984 215.863 76.523 234.968 83.6C253.961 90.486 271.299 100.241 286.98 112.864C302.661 125.488 315.14 139.833 324.416 155.899C333.03 170.817 336.841 183.918 335.847 195.203C335.075 206.487 331.376 216.336 324.75 224.751C318.346 233.167 308.408 243.494 294.936 255.734L377.094 255.737L405.917 305.656L217.087 305.649L188.263 255.73Z"
                            fill="black" />
                        <path
                            d="M9.11884 126.339L-13.7396 126.338L-42.7286 76.132L43.0733 76.135L175.595 305.649L112.651 305.647L9.11884 126.339Z"
                            fill="black" />
                        <path
                            d="M204.592 227.449L204.923 227.449C211.769 220.564 222.094 211.479 235.897 200.196C249.258 188.912 259.306 179.923 266.042 173.23C272.668 166.345 277.195 159.077 279.624 151.427C282.053 143.777 280.893 135.839 276.145 127.615C270.844 118.435 263.39 111.071 253.782 105.524C244.285 99.786 234.236 96.917 223.635 96.916C213.255 96.916 206.464 99.689 203.262 105.235C199.949 110.59 201.054 118.049 206.575 127.612L141.975 127.61C132.699 111.544 128.723 97.2 130.048 84.577C131.593 71.954 137.887 62.2 148.93 55.315C160.083 48.239 174.935 44.701 193.487 44.702C212.922 44.703 232.192 48.242 251.296 55.319C270.289 62.205 287.627 71.96 303.308 84.583C318.989 97.207 331.468 111.552 340.745 127.618C349.358 142.536 353.169 155.637 352.175 166.921C351.403 178.205 347.704 188.055 341.078 196.47C334.674 204.885 324.736 215.213 311.264 227.453L393.422 227.456L422.246 277.375L233.415 277.368L204.592 227.449Z"
                            fill="#F8B803" />
                        <path
                            d="M25.447 98.058L2.58852 98.057L-26.4005 47.851L59.4015 47.854L191.923 277.368L128.979 277.365L25.447 98.058Z"
                            fill="#F8B803" />
                    </g>
                </svg>

                <div
                    class="absolute inset-0 rounded-t-lg lg:rounded-t-none lg:rounded-r-lg shadow-[inset_0px_0px_0px_1px_rgba(26,26,0,0.16)] dark:shadow-[inset_0px_0px_0px_1px_#fffaed2d]">
                </div>
            </div>
        </main>
    </div>

    @if (Route::has('login'))
        <div class="h-14.5 hidden lg:block"></div>
    @endif
</body>

</html>