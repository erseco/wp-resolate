(function(){
'use strict';

function replacePlaceholders(element, index) {
var placeholder = /__INDEX__/g;
if (element.hasAttribute('data-index')) {
element.setAttribute('data-index', element.getAttribute('data-index').replace(placeholder, index));
}
var nodes = element.querySelectorAll('*');
nodes.forEach(function(node) {
Array.prototype.slice.call(node.attributes).forEach(function(attr) {
if (attr.value && attr.value.indexOf('__INDEX__') !== -1) {
node.setAttribute(attr.name, attr.value.replace(placeholder, index));
}
});
});
}

function updateIndexes(container, slug) {
var items = container.querySelectorAll('.resolate-array-item');
items.forEach(function(item, idx) {
item.setAttribute('data-index', String(idx));
var fields = item.querySelectorAll('input, textarea');
fields.forEach(function(field) {
var name = field.getAttribute('name');
if (name) {
field.setAttribute('name', name.replace(/\[[0-9]+\](?=\[[^\[]+\]$)/, '[' + idx + ']'));
}
var id = field.getAttribute('id');
if (id) {
field.id = id.replace(/-\d+$/, '-' + idx);
}
});
var labels = item.querySelectorAll('label[for]');
labels.forEach(function(label) {
var target = label.getAttribute('for');
if (target) {
label.setAttribute('for', target.replace(/-\d+$/, '-' + idx));
}
});
});
}

function getDragAfterElement(container, y) {
var draggableElements = Array.prototype.slice.call(container.querySelectorAll('.resolate-array-item:not(.is-dragging)'));
return draggableElements.reduce(function(closest, child) {
var box = child.getBoundingClientRect();
var offset = y - box.top - box.height / 2;
if (offset < 0 && offset > closest.offset) {
return { offset: offset, element: child };
}
return closest;
}, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
}

function initArrayField(field) {
var slug = field.getAttribute('data-array-field');
if (!slug) {
return;
}
var container = field.querySelector('.resolate-array-items');
var template = field.querySelector('.resolate-array-template');
var addButton = field.querySelector('.resolate-array-add');
if (!container || !template || !addButton) {
return;
}

var dragSrc = null;

function addItem() {
var index = container.querySelectorAll('.resolate-array-item').length;
var clone = document.importNode(template.content, true);
var item = clone.querySelector('.resolate-array-item');
replacePlaceholders(item, index);
container.appendChild(clone);
updateIndexes(container, slug);
}

addButton.addEventListener('click', function(event) {
event.preventDefault();
addItem();
});

container.addEventListener('click', function(event) {
if (event.target.classList.contains('resolate-array-remove')) {
event.preventDefault();
var item = event.target.closest('.resolate-array-item');
if (item) {
item.parentNode.removeChild(item);
if (!container.querySelector('.resolate-array-item')) {
addItem();
}
updateIndexes(container, slug);
}
}
});

container.addEventListener('dragstart', function(event) {
var item = event.target.closest('.resolate-array-item');
if (!item) {
return;
}
dragSrc = item;
item.classList.add('is-dragging');
event.dataTransfer.effectAllowed = 'move';
event.dataTransfer.setData('text/plain', '');
});

container.addEventListener('dragend', function(event) {
if (dragSrc) {
dragSrc.classList.remove('is-dragging');
dragSrc = null;
}
});

container.addEventListener('dragover', function(event) {
if (!dragSrc) {
return;
}
event.preventDefault();
var afterElement = getDragAfterElement(container, event.clientY);
if (!afterElement) {
container.appendChild(dragSrc);
} else if (afterElement !== dragSrc) {
container.insertBefore(dragSrc, afterElement);
}
});

container.addEventListener('drop', function(event) {
if (!dragSrc) {
return;
}
event.preventDefault();
updateIndexes(container, slug);
});

updateIndexes(container, slug);
}

document.addEventListener('DOMContentLoaded', function() {
var fields = document.querySelectorAll('.resolate-array-field');
fields.forEach(initArrayField);
});
})();
