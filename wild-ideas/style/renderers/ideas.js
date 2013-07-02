Jsonary.plugins.LinkTableRenderer({
	linkHandler: function (link, submittedData, request) {
		return false;
		console.log(link.href);
	}
})
.addLinkColumn('edit', '', 'edit', 'save', true)
.addLinkColumn('delete', '', 'delete', 'cancel', false)
.addColumn('/title', 'Title')
.addColumn('/feasibility', 'Feasibility')
.register(function (data, schemas) {
	return schemas.containsUrl('idea#/definitions/array');
});