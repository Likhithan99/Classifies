(() => {
    document.querySelectorAll('[data-location-picker]').forEach((picker) => {
        const trigger = picker.querySelector('.location-picker-trigger');
        const menu = picker.querySelector('[data-location-menu]');
        const search = picker.querySelector('[data-location-search]');
        const areaOptions = [...picker.querySelectorAll('[data-area-choice]')];
        const groups = [...picker.querySelectorAll('[data-area-group]')];
        const locationId = picker.closest('form').querySelector('[name="location_id"]');
        const selectedLocation = picker.querySelector('[data-selected-location]');
        const setActiveArea = (areaId) => {
            areaOptions.forEach((option) => {
                const active = option.dataset.areaId === areaId;
                option.classList.toggle('is-active', active);
                option.setAttribute('aria-selected', String(active));
            });
            groups.forEach((group) => group.classList.toggle('is-active', group.dataset.areaGroup === areaId));
        };
        const closeMenu = () => {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        };

        trigger.addEventListener('click', () => {
            const isOpen = trigger.getAttribute('aria-expanded') === 'true';
            menu.hidden = isOpen;
            trigger.setAttribute('aria-expanded', String(!isOpen));
            if (!isOpen) search.focus();
        });
        areaOptions.forEach((option) => {
            option.addEventListener('mouseenter', () => setActiveArea(option.dataset.areaId));
            option.addEventListener('focus', () => setActiveArea(option.dataset.areaId));
            option.addEventListener('click', () => setActiveArea(option.dataset.areaId));
        });
        search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            let firstVisibleArea = '';
            groups.forEach((group) => {
                const choices = [...group.querySelectorAll('[data-location-choice]')];
                const areaMatches = group.dataset.areaName.toLocaleLowerCase().includes(query);
                let hasMatch = false;
                choices.forEach((choice) => {
                    const matches = !query || areaMatches || choice.dataset.locationName.toLocaleLowerCase().includes(query);
                    choice.hidden = !matches;
                    hasMatch ||= matches;
                });
                group.hidden = !hasMatch;
                if (hasMatch && !firstVisibleArea) firstVisibleArea = group.dataset.areaGroup;
            });
            areaOptions.forEach((option) => {
                const matchingGroup = groups.find((group) => group.dataset.areaGroup === option.dataset.areaId);
                option.hidden = !matchingGroup || matchingGroup.hidden;
            });
            if (query && firstVisibleArea) setActiveArea(firstVisibleArea);
        });
        picker.querySelectorAll('[data-location-choice]').forEach((choice) => {
            choice.addEventListener('click', () => {
                locationId.value = choice.dataset.locationId;
                selectedLocation.textContent = choice.dataset.locationName;
                closeMenu();
                picker.closest('form').requestSubmit();
            });
        });
        document.addEventListener('click', (event) => {
            if (!picker.contains(event.target)) closeMenu();
        });
        picker.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeMenu();
                trigger.focus();
            }
        });
    });

    const feed = document.querySelector('[data-listing-feed]');
    const sentinel = document.querySelector('[data-feed-sentinel]');
    const loader = document.querySelector('[data-feed-loader]');
    const endMessage = document.querySelector('[data-feed-end]');
    const cardMarkup = (item, locationId) => {
        const card = document.createElement('article');
        card.className = 'listing-card';
        card.dataset.tilt = '';
        const photoLink = document.createElement('a');
        photoLink.className = 'listing-photo';
        photoLink.href = `/?page=listing&id=${encodeURIComponent(item.id)}&category=${encodeURIComponent(item.category_slug)}&location_id=${encodeURIComponent(locationId)}`;
        const image = document.createElement('img');
        image.src = item.image_url;
        image.alt = item.title;
        image.loading = 'lazy';
        image.decoding = 'async';
        photoLink.append(image);
        if (Number(item.is_featured)) {
            const badge = document.createElement('span');
            badge.className = 'photo-badge';
            badge.textContent = 'NEIGHBOR PICK';
            photoLink.append(badge);
        }
        const copy = document.createElement('div');
        copy.className = 'listing-copy';
        const topline = document.createElement('div');
        topline.className = 'listing-topline';
        const category = document.createElement('a');
        category.className = 'listing-category';
        category.href = `/?category=${encodeURIComponent(item.category_slug)}&location_id=${encodeURIComponent(locationId)}`;
        category.textContent = item.category_name;
        const location = document.createElement('span');
        location.className = 'listing-location';
        location.textContent = `⌖ ${item.location || 'Nearby'}`;
        topline.append(category, location);
        const titleLink = document.createElement('a');
        titleLink.className = 'listing-title-link';
        titleLink.href = photoLink.href;
        const title = document.createElement('h2');
        title.textContent = item.title;
        titleLink.append(title);
        const description = document.createElement('p');
        description.className = 'listing-description';
        description.textContent = item.description;
        const bottom = document.createElement('div');
        bottom.className = 'listing-bottom';
        const price = document.createElement('strong');
        price.className = 'listing-price';
        price.textContent = `$${Number(item.price).toLocaleString('en-US', { minimumFractionDigits: 2 })}`;
        const more = document.createElement('a');
        more.className = 'more-link';
        more.href = photoLink.href;
        more.append(document.createTextNode('See the details '));
        const arrow = document.createElement('span');
        arrow.textContent = '↗';
        more.append(arrow);
        bottom.append(price, more);
        copy.append(topline, titleLink, description, bottom);
        const sideMark = document.createElement('span');
        sideMark.className = 'card-side-mark';
        sideMark.setAttribute('aria-hidden', 'true');
        card.append(photoLink, copy, sideMark);
        return card;
    };

    if (feed && sentinel && loader && endMessage && 'IntersectionObserver' in window) {
        let offset = Number(feed.dataset.offset || 0);
        const total = Number(feed.dataset.total || 0);
        let loading = false;
        let exhausted = offset >= total;
        const observer = new IntersectionObserver(async (entries) => {
            if (!entries.some((entry) => entry.isIntersecting) || loading || exhausted) return;
            loading = true;
            loader.hidden = false;
            try {
                const params = new URLSearchParams({ action: 'load_listings', offset: String(offset), category: feed.dataset.category || '', q: feed.dataset.query || '', location_id: document.querySelector('[data-location-picker]')?.closest('form').querySelector('[name="location_id"]').value || '' });
                const response = await fetch(`/?${params.toString()}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Unable to load more classifieds.');
                const data = await response.json();
                const fragment = document.createDocumentFragment();
                data.items.forEach((item) => fragment.append(cardMarkup(item, feed.dataset.locationId || '')));
                feed.append(fragment);
                offset = Number(data.nextOffset);
                exhausted = offset >= Number(data.total) || data.items.length === 0;
                if (exhausted) {
                    observer.disconnect();
                    endMessage.hidden = false;
                }
            } catch {
                loader.querySelector('span:last-child').textContent = 'Could not load more just now. Scroll to try again.';
            } finally {
                loader.hidden = exhausted;
                loading = false;
            }
        }, { rootMargin: '360px 0px' });
        if (!exhausted) observer.observe(sentinel);
    }

    document.addEventListener('pointermove', (event) => {
        const card = event.target.closest('[data-tilt]');
        if (!card || event.pointerType === 'touch') return;
        const bounds = card.getBoundingClientRect();
        const horizontal = (event.clientX - bounds.left) / bounds.width - 0.5;
        const vertical = (event.clientY - bounds.top) / bounds.height - 0.5;
        card.style.setProperty('--rotate-y', `${horizontal * 2.3}deg`);
        card.style.setProperty('--rotate-x', `${vertical * -2.1}deg`);
    });
    document.addEventListener('pointerout', (event) => {
        const card = event.target.closest('[data-tilt]');
        if (card && !card.contains(event.relatedTarget)) {
            card.style.removeProperty('--rotate-x');
            card.style.removeProperty('--rotate-y');
        }
    });
})();
