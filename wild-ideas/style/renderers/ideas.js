Jsonary.plugins.FancyTableRenderer({
	linkHandler: function (link, submittedData, request) {
		return false;
	},
	rowsPerPage: 5,
	sort: {
		'/id': true,
		'/title': true,
		'/feasibility': function (a, b) {
			var feasibilityOrder = ['unknown', 'impossible', 'unlikely', 'possible', 'likely', 'certain'];
			return feasibilityOrder.indexOf(a) - feasibilityOrder.indexOf(b);
		}
	}
})
.addLinkColumn('edit', '', 'edit', 'save', true)
.addLinkColumn('delete', '', 'delete', 'cancel', false)
.addColumn('/id', '#')
.addColumn('/title', 'Title')
.addColumn('/feasibility', 'Feasibility')
.register(function (data, schemas) {
	return schemas.containsUrl('idea#/definitions/array');
});