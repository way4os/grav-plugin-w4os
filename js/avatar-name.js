// The first and last names of an avatar as the viewers take them, while they are typed (as w4os-wp does it): the accents made plain
// letters, what is not a letter or a number left out, a letter first, capitalized; the fields are the ones with that form
// (pattern [A-Za-z][A-Za-z0-9]*), the browser telling the rest.
document.querySelectorAll('input[pattern="[A-Za-z][A-Za-z0-9]*"]').forEach((input) => {
    const plain = { æ: 'ae', Æ: 'AE', ø: 'o', Ø: 'O', œ: 'oe', Œ: 'OE', ß: 'ss' };
    const clean = (value) => {
        let name = value.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[æÆøØœŒß]/g, (letter) => plain[letter]);
        name = name.replace(/[^A-Za-z0-9]/g, '').replace(/^[0-9]+/, '');
        return name.charAt(0).toUpperCase() + name.slice(1);
    };
    input.addEventListener('input', () => {
        const name = clean(input.value);
        name !== input.value && (input.value = name);
    });
});
