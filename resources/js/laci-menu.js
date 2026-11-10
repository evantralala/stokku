/**
 * Laci menu untuk layar < 1024px.
 * Tombol pembuka: [data-buka-menu], penutup: [data-tutup-menu], laci: #sidebar.
 */
const LAYAR_LEBAR = window.matchMedia("(min-width: 1024px)");

export function pasangLaciMenu() {
    const laci = document.getElementById("sidebar");
    const tirai = document.querySelector(".tirai");

    if (!laci || !tirai) {
        return;
    }

    const tombolBuka = document.querySelectorAll("[data-buka-menu]");
    let pemicu = null;

    const aturAria = (terbuka) => {
        tombolBuka.forEach((tombol) =>
            tombol.setAttribute("aria-expanded", String(terbuka)),
        );
    };

    // Di HP laci yang tertutup tidak boleh bisa difokus dengan Tab.
    const aturInert = () => {
        laci.inert =
            !LAYAR_LEBAR.matches &&
            !document.body.classList.contains("menu-terbuka");
    };

    const buka = (tombol) => {
        pemicu = tombol;
        document.body.classList.add("menu-terbuka");
        tirai.hidden = false;
        aturAria(true);
        aturInert();
        laci.querySelector(".nav-item.on, .nav-item")?.focus();
    };

    const tutup = () => {
        if (!document.body.classList.contains("menu-terbuka")) {
            return;
        }
        document.body.classList.remove("menu-terbuka");
        tirai.hidden = true;
        aturAria(false);
        aturInert();
        pemicu?.focus();
    };

    tombolBuka.forEach((tombol) =>
        tombol.addEventListener("click", () => buka(tombol)),
    );
    document
        .querySelectorAll("[data-tutup-menu]")
        .forEach((el) => el.addEventListener("click", tutup));

    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            tutup();
        }
    });

    LAYAR_LEBAR.addEventListener("change", () => {
        tutup();
        aturInert();
    });

    aturInert();
}
